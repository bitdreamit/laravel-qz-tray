/**
 * SmartPrint — Laravel QZ Tray client library
 * Connects to QZ Tray for silent, dialog-free printing.
 *
 * Usage:
 *   <button data-qz-print="/invoice/1.pdf" data-qz-printer="Receipt Printer">Print</button>
 *   <div data-qz-auto-print="/receipt/1.pdf" data-qz-delay="1000"></div>
 *   smartPrint('/doc.pdf', { printer: 'HP', copies: 2 });
 */
window.SmartPrint = (() => {
    const STORAGE_PREFIX = 'smart_printer:';
    const GLOBAL_KEY     = 'smart_printer_global';
    const DEVICE_ID_KEY  = 'smart_print_device_id';
    let processingQueue  = false; // prevent concurrent processQueue calls

    // ============================
    // UUID helpers
    // ============================
    // Prefer crypto.randomUUID (all modern browsers). Fall back to a
    // template-based uuid4 generator for older WebViews / embedded Trident
    // browsers sometimes used on lab/kiosk workstations that don't expose it.
    function uuid4() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    // Time-ordered UUID (RFC 9562). No browser exposes a native v7 API yet
    // (crypto.randomUUID() is v4-only as of this writing), so this is a
    // manual implementation: 48-bit millisecond timestamp + version nibble
    // (0111) + 74 bits of randomness + variant bits (10). Used as the
    // job.id sent to POST /qz/print — when config('qz-tray.id_type') is
    // 'uuid', that id becomes the qz_print_jobs primary key directly (see
    // QzSecurityController::print()), so a v4-random PK would scatter
    // inserts randomly across the table's B-tree index; v7's leading
    // timestamp keeps new rows appending near the end instead, same index
    // locality benefit as an auto-increment bigint.
    function uuid7() {
        const ms = Date.now();
        const tsHex = ms.toString(16).padStart(12, '0').slice(-12); // 48 bits

        const rnd = new Uint8Array(10); // rand_a (12 bits) + rand_b (62 bits) = 74 bits needed; 10 bytes (80) is comfortably enough
        if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
            crypto.getRandomValues(rnd);
        } else {
            for (let i = 0; i < rnd.length; i++) rnd[i] = Math.floor(Math.random() * 256);
        }

        // version (4 bits, = 0111) + rand_a (12 bits, from rnd[0] + top
        // nibble of rnd[1]) = 16 bits = 4 hex chars
        const verRandA = (0x7000 | (((rnd[0] << 4) | (rnd[1] >> 4)) & 0x0fff))
            .toString(16).padStart(4, '0');

        // variant (2 bits, = 10, folded into rnd[2]) + rand_b (62 bits,
        // from rnd[2]'s remaining 6 bits + rnd[3..9]) = 64 bits = 16 hex chars
        const variantByte = (rnd[2] & 0x3f) | 0x80;
        const tail = [variantByte, rnd[3], rnd[4], rnd[5], rnd[6], rnd[7], rnd[8], rnd[9]]
            .map(b => b.toString(16).padStart(2, '0')).join('');

        return `${tsHex.slice(0, 8)}-${tsHex.slice(8, 12)}-${verRandA}-${tail.slice(0, 4)}-${tail.slice(4, 16)}`;
    }

    // Entry point for every job id generated in this file: v7 when enabled
    // (default) for its DB index-locality benefit, transparently falling
    // back to v4 if v7 generation throws for any reason (e.g. an
    // environment without Uint8Array or Math.random — practically never,
    // but a job id must never block a print). Config mirrors the server's
    // qz-tray.uuid_version so both sides make the same choice by default;
    // it isn't actually required to match (both are valid uuid column
    // values either way), it just keeps ids consistently time-sortable
    // when they do.
    function generateJobId() {
        if (window.QZ_CONFIG && window.QZ_CONFIG.uuidVersion === 'v4') {
            return uuid4();
        }
        try {
            return uuid7();
        } catch (e) {
            return uuid4();
        }
    }

    // Persistent per-browser identifier for THIS workstation. Distinct from
    // job ids: it never changes once generated, so the server can tell two
    // different physical machines apart even when they share a Laravel
    // session/login (e.g. multiple lab PCs logged in as the same clinic
    // account). Used to scope server-side printer memory and print-job
    // logging so one workstation's settings/queue never leak into another's.
    function getDeviceId() {
        try {
            let id = localStorage.getItem(DEVICE_ID_KEY);
            if (!id) {
                id = uuid4();
                localStorage.setItem(DEVICE_ID_KEY, id);
            }
            return id;
        } catch (e) {
            // localStorage unavailable (private browsing) — fall back to an
            // in-memory id that's at least stable for this page session.
            return state._volatileDeviceId || (state._volatileDeviceId = uuid4());
        }
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    // Server sync is opt-out via window.QZ_CONFIG.serverSync = false, for
    // deployments that only ever want the localStorage-only behavior of
    // pre-1.1 releases.
    function serverSyncEnabled() {
        return !(window.QZ_CONFIG && window.QZ_CONFIG.serverSync === false);
    }

    const state = {
        qzReady:      false,
        connecting:   false,
        printers:     [],
        currentPrinter: null,
        queue:        [],
        failedQueue:  [],
        listeners:    {},
        channel:      (typeof BroadcastChannel !== 'undefined')
                        ? new BroadcastChannel('smart-print')
                        : null,
    };

    // Path key: use full pathname for per-page printer memory
    const pathKey = () => location.pathname;

    // ============================
    // Event emitter
    // ============================
    function emit(event, data) {
        (state.listeners[event] || []).forEach(fn => {
            try { fn(data); } catch (e) { console.error('[SmartPrint] listener error', e); }
        });
    }

    // ============================
    // QZ Security
    // ============================
    function setupSecurity() {
        if (!window.qz) return;

        qz.security.setCertificatePromise(resolve =>
            fetch('/qz/certificate', {
                cache: 'no-store',
                headers: { 'X-Device-Id': getDeviceId() },
            })
                .then(r => {
                    if (!r.ok) throw new Error('Certificate fetch failed: ' + r.status);
                    return r.text();
                })
                .then(resolve)
        );

        qz.security.setSignatureAlgorithm('SHA512');

        qz.security.setSignaturePromise(toSign => (resolve, reject) =>
            fetch('/qz/sign', {
                method: 'POST',
                cache:  'no-store',
                headers: {
                    'Content-Type':  'application/json',
                    'X-CSRF-TOKEN':  csrfToken(),
                    'X-Device-Id':   getDeviceId(),
                    'Accept':        'text/plain',
                },
                body: JSON.stringify({ data: toSign }),
            })
            .then(r => r.ok ? r.text().then(resolve) : r.text().then(t => reject(new Error(t))))
            .catch(reject)
        );
    }

    // ============================
    // Connect QZ Tray
    // ============================
    async function connectQZ(retries = 2) {
        if (!window.qz) {
            console.warn('[SmartPrint] QZ Tray library not loaded. Add qz-tray.min.js to your page.');
            emit('init-failed', { reason: 'qz-library-missing' });
            return false;
        }

        if (qz.websocket.isActive()) return true;

        if (state.connecting) {
            // Wait for the existing attempt to finish
            await new Promise(r => setTimeout(r, 200));
            return qz.websocket.isActive();
        }

        state.connecting = true;
        setupSecurity();

        try {
            await qz.websocket.connect();
            state.qzReady  = true;
            state.printers = await qz.printers.find();
            restorePrinter();
            emit('connected', { printers: state.printers });
            emit('printers-loaded', { printers: state.printers });
            return true;
        } catch (err) {
            if (retries > 0) {
                // Attempt to launch QZ Tray via a hidden iframe so we don't
                // navigate the current page away (which `location.assign`
                // would do). The protocol handler `qz:launch` is ignored
                // silently by the browser when QZ Tray isn't installed.
                try {
                    launchQZProtocol();
                } catch (_) {}
                await new Promise(r => setTimeout(r, 1500));
                state.connecting = false;
                return connectQZ(retries - 1);
            }
            state.qzReady = false;
            emit('connection-failed', { error: err });
            return false;
        } finally {
            state.connecting = false;
        }
    }

    // Trigger the `qz:launch` protocol handler without leaving the page.
    // Uses a hidden iframe so a missing handler does not produce a
    // "The address wasn't understood" navigation.
    function launchQZProtocol() {
        const existing = document.getElementById('sp-qz-launch');
        if (existing) existing.remove();
        const iframe = document.createElement('iframe');
        iframe.id = 'sp-qz-launch';
        iframe.style.display = 'none';
        iframe.src = 'qz:launch';
        document.body.appendChild(iframe);
        // Clean up after a short delay so the protocol is invoked.
        setTimeout(() => { if (iframe.parentNode) iframe.remove(); }, 2000);
    }

    // ============================
    // Printer Memory
    // ============================
    // Page-wide tenant/project default, set by the host app once
    // (window.QZ_CONFIG.tenantId / .projectId) — same convention logPrintJob()
    // already uses. Returns undefined when the app is single-tenant, in
    // which case the server's resolveTenantId() falls back to its own
    // tenant_id_resolver config (or null) — the client never needs to know
    // which.
    function pageTenantId() {
        return window.QZ_CONFIG && (window.QZ_CONFIG.tenantId ?? window.QZ_CONFIG.projectId);
    }

    function restorePrinter() {
        let saved = null;
        try {
            saved = localStorage.getItem(STORAGE_PREFIX + pathKey())
                 || localStorage.getItem(GLOBAL_KEY);
            // Only restore if printer is in the current list (or list is empty = first connect)
            if (saved && (state.printers.length === 0 || state.printers.includes(saved))) {
                state.currentPrinter = saved;
            } else {
                saved = null;
            }
        } catch (e) {
            // localStorage may be unavailable (private browsing, etc.)
        }

        // localStorage is per-browser, so it already isolates two different
        // workstations from each other. The server round-trip below exists
        // for the OTHER case: this same workstation's browser profile was
        // reset/cleared, or a fresh browser is opened on the same physical
        // device — server memory (scoped by the device UUID, which is
        // regenerated only if localStorage itself is cleared) lets it pick
        // its printer back up without asking again. It never overrides a
        // value localStorage already had.
        if (!saved && serverSyncEnabled()) {
            const tenantId = pageTenantId();
            const qs = tenantId ? ('?tenant_id=' + encodeURIComponent(tenantId)) : '';
            fetch('/qz/printer/' + encodeURIComponent(pathKey()) + qs, {
                headers: { 'X-Device-Id': getDeviceId() },
                cache: 'no-store',
            })
                .then(r => r.ok ? r.json() : null)
                .then(json => {
                    const printer = json && json.printer;
                    if (printer && !state.currentPrinter
                        && (state.printers.length === 0 || state.printers.includes(printer))) {
                        state.currentPrinter = printer;
                        try { localStorage.setItem(STORAGE_PREFIX + pathKey(), printer); } catch (e) {}
                        emit('printer-restored', { printer, source: json.scoped_to || 'default' });
                    }
                })
                .catch(() => {}); // best-effort; localStorage/modal remain the source of truth
        }
    }

    function rememberPrinter(printer, scope) {
        scope = scope || 'path';
        state.currentPrinter = printer;
        try {
            const storageKey = (scope === 'global') ? GLOBAL_KEY : STORAGE_PREFIX + pathKey();
            localStorage.setItem(storageKey, printer);
        } catch (e) {}

        if (state.channel) {
            state.channel.postMessage({ printer });
        }

        if (serverSyncEnabled()) {
            const tenantId = pageTenantId();
            fetch('/qz/printer', {
                method: 'POST',
                cache:  'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Device-Id':  getDeviceId(),
                    'Accept':       'application/json',
                },
                body: JSON.stringify({
                    printer,
                    path:      pathKey(),
                    device_id: getDeviceId(),
                    tenant_id: tenantId !== undefined ? String(tenantId) : undefined,
                }),
            }).catch(() => {}); // fire-and-forget; localStorage already has the authoritative copy
        }

        emit('printer-saved', { printer, scope });
    }

    if (state.channel) {
        state.channel.onmessage = e => {
            if (e.data && e.data.printer) {
                state.currentPrinter = e.data.printer;
            }
        };
    }

    // ============================
    // Queue Management
    // ============================
    // Idempotent: if `job` already carries a pending `_promise` (e.g. it is
    // being re-submitted after the printer-selection modal was answered),
    // that same promise is reused instead of creating a second, orphaned
    // one — otherwise a caller doing `await SmartPrint.print(...)` before
    // any printer had been chosen would hang forever, because the original
    // promise would never be the one actually printed.
    function enqueue(job) {
        if (!job._promise) {
            job.id = job.id || generateJobId();
            job._promise = new Promise((resolve, reject) => {
                job._resolve = resolve;
                job._reject  = reject;
            });
            // Don't let an unawaited enqueue() (the common DOM-click path)
            // produce an "Uncaught (in promise)" console error.
            job._promise.catch(() => {});
        }
        state.queue.push(job);
        updateQueueUI();
        emit('job-queued', { job });
        processQueue();
        return job._promise;
    }

    async function processQueue() {
        if (processingQueue || !state.queue.length) return;
        processingQueue = true;

        while (state.queue.length) {
            const job = state.queue.shift();
            emit('job-processing', { job });
            try {
                const connected = await connectQZ();
                if (connected) {
                    await printQZ(job);
                } else {
                    offlineBuffer(job);
                }
            } catch (err) {
                console.error('[SmartPrint] Job error:', err);
                offlineBuffer(job);
            }
        }

        processingQueue = false;
        updateQueueUI();
    }

    function offlineBuffer(job) {
        state.failedQueue.push(job);
        try {
            // Strip function/promise fields — JSON.stringify silently drops
            // functions anyway, but being explicit avoids surprises if a
            // future job field holds a class instance with a toJSON trap.
            const { _resolve, _reject, _promise, ...serializable } = job;
            const offline = JSON.parse(localStorage.getItem('sp_offline_queue') || '[]');
            offline.push(serializable);
            localStorage.setItem('sp_offline_queue', JSON.stringify(offline));
        } catch (e) {}
        emit('job-failed', { job });
        // The original caller (if any) gets a clear rejection now rather
        // than hanging until an eventual retry succeeds minutes/hours later.
        job._reject && job._reject(new Error('QZ Tray offline; job stored for retry'));
        console.warn('[SmartPrint] QZ Tray offline – job stored for retry.');
    }

    function retryOffline() {
        try {
            const offline = JSON.parse(localStorage.getItem('sp_offline_queue') || '[]');
            if (offline.length) {
                offline.forEach(job => enqueue(job));
                localStorage.removeItem('sp_offline_queue');
            }
        } catch (e) {}
    }

    // ============================
    // Server-side job logging (best-effort, non-blocking)
    // ============================
    // Sends the SAME client-generated job.id as `job_id` so the row created
    // here is the one GET /qz/jobs and DELETE /qz/jobs/{id} can look up —
    // previously the server minted its own uniqid() that no client code
    // ever saw, so the queue/cancel endpoints were unreachable from the UI.
    function logPrintJob(job, printer, status) {
        if (!serverSyncEnabled()) return;
        // Per-job value wins; otherwise fall back to a page-wide default set
        // by the host app (e.g. window.QZ_CONFIG.tenantId = '{{ $project->id }}'
        // — works whether that id is a bigint or a uuid string).
        const tenantId = job.tenantId ?? job.projectId
            ?? (window.QZ_CONFIG && (window.QZ_CONFIG.tenantId ?? window.QZ_CONFIG.projectId))
            ?? undefined;

        fetch('/qz/print', {
            method: 'POST',
            cache:  'no-store',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Device-Id':  getDeviceId(),
                'Accept':       'application/json',
            },
            body: JSON.stringify({
                job_id:    job.id,
                printer,
                type:      job.type,
                url:       job.url || undefined,
                data:      job.url ? undefined : (job.data || undefined),
                copies:    job.copies,
                device_id: getDeviceId(),
                tenant_id: tenantId !== undefined ? String(tenantId) : undefined,
                metadata:  { status: status || 'completed' },
            }),
        }).catch(() => {}); // logging failure must never block/alter the print result
    }

    // Invokes job.onComplete/job.onError if the caller supplied one via the
    // options object (documented in the README's "Options Object" section,
    // but never actually called anywhere before 1.1).
    function safeCallback(fn, ...args) {
        if (typeof fn !== 'function') return;
        try { fn(...args); } catch (e) { console.error('[SmartPrint] job callback error', e); }
    }

    // ============================
    // Core print function
    // ============================
    async function printQZ(job) {
        const printer = job.printer || state.currentPrinter;

        if (!printer) {
            // Promise stays pending — resolved/rejected once the user
            // answers the printer-selection modal (see openPrinterModal).
            openPrinterModal(job);
            return;
        }

        rememberPrinter(printer);

        const cfgOpts = { copies: parseInt(job.copies, 10) || 1 };

        // PDF size profile support
        const pdfProfiles = {
            default: { width: 210, height: 297, scale: 1.0 },
            small:   { width: 80,  height: 297, scale: 0.8 },  // thermal 80mm
            label:   { width: 100, height: 150, scale: 1.0 },
        };

        if (job.type === 'pdf' && job.profile) {
            const profile = pdfProfiles[job.profile] || pdfProfiles.default;
            cfgOpts.size         = { width: profile.width, height: profile.height };
            cfgOpts.scaleContent = profile.scale;
            cfgOpts.units        = 'mm';
        }

        const cfg = qz.configs.create(printer, cfgOpts);

        let payload;
        switch (job.type) {
            case 'pdf':
                if (!job.url) {
                    const err = new Error('Missing PDF url');
                    console.error('[SmartPrint] PDF print requires a url.');
                    emit('job-failed', { job, error: err });
                    safeCallback(job.onError, err, job);
                    job._reject && job._reject(err);
                    return;
                }
                payload = [{ type: 'pdf', data: job.url }];
                break;
            case 'html':
                if (!job.data && !job.url) {
                    const err = new Error('Missing HTML data');
                    console.error('[SmartPrint] HTML print requires data or url.');
                    emit('job-failed', { job, error: err });
                    safeCallback(job.onError, err, job);
                    job._reject && job._reject(err);
                    return;
                }
                payload = [{ type: 'html', data: job.data || job.url }];
                break;
            case 'zpl':
            case 'raw':
            case 'escpos':
                if (!job.data) {
                    const err = new Error('Missing raw data for ' + job.type + ' print');
                    console.error('[SmartPrint] ' + job.type + ' print requires data.');
                    emit('job-failed', { job, error: err });
                    safeCallback(job.onError, err, job);
                    job._reject && job._reject(err);
                    return;
                }
                payload = [{ type: 'raw', format: 'command', data: job.data }];
                break;
            default:
                // Unrecognised type: the browser print dialog is the best
                // we can do, so treat it as a (non-silent) success rather
                // than leaving the promise unsettled.
                fallback(job);
                emit('job-completed', { job, fallback: true });
                safeCallback(job.onComplete, job);
                job._resolve && job._resolve({ jobId: job.id, success: true, fallback: true });
                return;
        }

        try {
            await qz.print(cfg, payload);
            emit('job-completed', { job });
            logPrintJob(job, printer, 'completed');
            safeCallback(job.onComplete, job);
            job._resolve && job._resolve({ jobId: job.id, success: true });
        } catch (err) {
            console.error('[SmartPrint] Print error:', err);
            emit('job-failed', { job, error: err });
            safeCallback(job.onError, err, job);
            fallback(job);
            job._reject && job._reject(err);
        }
    }

    // ============================
    // Browser fallback
    // ============================
    function fallback(job) {
        emit('fallback-print', { job });

        if (job.type === 'pdf' && job.url) {
            const w = window.open(job.url, '_blank');
            if (w) {
                w.onload = () => { try { w.print(); } catch (_) {} };
            }
            return;
        }

        if (job.type === 'html') {
            const w = window.open('', '_blank');
            if (w) {
                w.document.write(job.data || '');
                w.document.close();
                w.onload = () => { try { w.print(); } catch (_) {} };
            }
            return;
        }

        console.warn('[SmartPrint] Silent printing unavailable. Install QZ Tray: https://qz.io/download');
    }

    // ============================
    // Printer selection modal
    // ============================
    function openPrinterModal(jobToQueue) {
        // Remove any existing modal
        const existing = document.getElementById('sp-printer-modal');
        if (existing) existing.remove();

        const modal = document.createElement('div');
        modal.id        = 'sp-printer-modal';
        modal.className = 'sp-modal';
        modal.style.cssText = [
            'position:fixed', 'inset:0', 'z-index:99999',
            'background:rgba(0,0,0,.5)', 'display:flex',
            'align-items:center', 'justify-content:center',
        ].join(';');

        const printerButtons = state.printers.length
            ? state.printers.map(p =>
                `<button data-printer="${p}" style="display:block;width:100%;margin:4px 0;padding:8px;cursor:pointer;">${p}</button>`
              ).join('')
            : '<p style="color:#888;">No printers found. Is QZ Tray running?</p>';

        modal.innerHTML = `
            <div class="sp-box" style="background:#fff;padding:24px;border-radius:8px;min-width:280px;max-width:400px;">
                <h3 style="margin:0 0 16px;">Select Printer</h3>
                ${printerButtons}
                <button id="sp-modal-cancel" style="margin-top:12px;padding:6px 12px;cursor:pointer;">Cancel</button>
            </div>`;

        document.body.appendChild(modal);

        modal.querySelectorAll('[data-printer]').forEach(btn => {
            btn.onclick = () => {
                const printer = btn.dataset.printer;
                rememberPrinter(printer);
                modal.remove();
                if (jobToQueue && (jobToQueue.url || jobToQueue.data)) {
                    // Mutate + re-enqueue the SAME job object rather than
                    // spreading it into a new one. enqueue() is idempotent
                    // on an object that already has `_promise`, so this
                    // resolves/rejects the original promise a caller may be
                    // awaiting instead of orphaning it behind a clone.
                    jobToQueue.printer = printer;
                    enqueue(jobToQueue);
                }
            };
        });

        const abandon = () => {
            modal.remove();
            if (jobToQueue && jobToQueue._reject) {
                jobToQueue._reject(new Error('Print cancelled: no printer selected'));
            }
        };

        modal.querySelector('#sp-modal-cancel').onclick = abandon;

        // Close on backdrop click
        modal.addEventListener('click', e => { if (e.target === modal) abandon(); });
    }

    // ============================
    // DOM Binding — supports both data-qz-print and legacy data-smart-print
    // ============================
    function bind() {
        document.addEventListener('click', e => {
            const el = e.target.closest('[data-qz-print], [data-smart-print]');
            if (!el) return;

            // Prevent default so buttons inside <form> don't submit.
            e.preventDefault();

            // Support both attribute naming conventions
            const url     = el.dataset.qzPrint     || el.dataset.url     || el.dataset.smartPrint;
            const printer = el.dataset.qzPrinter   || el.dataset.printer;
            const copies  = el.dataset.qzCopies    || el.dataset.copies;
            const type    = el.dataset.qzType      || el.dataset.type    || 'pdf';
            const data    = el.dataset.qzData      || el.dataset.data;
            const profile = el.dataset.qzProfile   || el.dataset.profile;
            const delay   = parseInt(el.dataset.qzDelay || el.dataset.delay || '0', 10);

            const job = { url, printer, copies: parseInt(copies, 10) || 1, type, data, profile };

            if (delay > 0) {
                setTimeout(() => enqueue(job), delay);
            } else {
                enqueue(job);
            }
        });

        // Auto-print elements: data-qz-auto-print="URL" or data-auto-print="true" + data-url="URL"
        document.querySelectorAll('[data-qz-auto-print], [data-auto-print="true"]').forEach(el => {
            const url     = el.dataset.qzAutoPrint || el.dataset.url;
            const printer = el.dataset.qzPrinter   || el.dataset.printer;
            const copies  = el.dataset.qzCopies    || el.dataset.copies;
            const type    = el.dataset.qzType      || el.dataset.type    || 'pdf';
            const data    = el.dataset.qzData      || el.dataset.data;
            const profile = el.dataset.qzProfile   || el.dataset.profile;
            const delay   = parseInt(el.dataset.qzDelay || el.dataset.delay || '0', 10);

            if (!url && !data) return; // nothing to print

            const job = { url, printer, copies: parseInt(copies, 10) || 1, type, data, profile };

            if (delay > 0) {
                setTimeout(() => enqueue(job), delay);
            } else {
                enqueue(job);
            }
        });
    }

    // ============================
    // Queue UI
    // ============================
    function updateQueueUI() {
        const container = document.getElementById('sp-queue-list');
        if (!container) return;

        container.innerHTML = '';
        state.queue.forEach(job => {
            const li = document.createElement('li');
            li.textContent = `Queued: ${job.type} — ${job.url || 'Raw Data'}`;
            container.appendChild(li);
        });
        state.failedQueue.forEach((job, i) => {
            const li = document.createElement('li');
            li.style.color = 'red';
            li.innerHTML = `Failed: ${job.type} — ${job.url || 'Raw Data'} `;
            const btn = document.createElement('button');
            btn.style.fontSize = '11px';
            btn.textContent = 'Retry';
            btn.onclick = () => retryJob(i);
            li.appendChild(btn);
            container.appendChild(li);
        });
    }

    function retryJob(index) {
        const job = state.failedQueue.splice(index, 1)[0];
        if (job) enqueue(job);
        updateQueueUI();
    }

    // ============================
    // Hotkey: Ctrl + Shift + P
    // ============================
    document.addEventListener('keydown', e => {
        // `e.key` is 'P' (uppercase) when Shift is held on most layouts.
        // Accept both 'P' and 'p' for robustness across keyboard layouts.
        if (e.ctrlKey && e.shiftKey && (e.key === 'P' || e.key === 'p')) {
            e.preventDefault();
            openPrinterModal(null);
        }
    });

    // ============================
    // Auto-reconnect every 10s if disconnected
    // ============================
    setInterval(() => {
        if (window.qz && !qz.websocket.isActive() && !state.connecting) {
            connectQZ(1).catch(() => {
                // Swallow — the connection-failed event already fires inside.
            });
        }
    }, 10000);

    // ============================
    // Init on DOMContentLoaded
    // ============================
    function init() {
        bind();
        connectQZ().then(() => {
            retryOffline();
            updateQueueUI();
            emit('ready', { printers: state.printers });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ============================
    // Public API
    // ============================
    return {
        // Core
        init,
        print: (urlOrOptions, options) => {
            if (typeof urlOrOptions === 'string') {
                return enqueue({ url: urlOrOptions, type: 'pdf', copies: 1, ...options });
            }
            // Normalise copies to an integer so downstream code can rely on it.
            const job = { ...urlOrOptions };
            if (job.copies !== undefined) job.copies = parseInt(job.copies, 10) || 1;
            return enqueue(job);
        },
        printRaw: (data, type, printer) => enqueue({ data, type: type || 'raw', printer, copies: 1 }),
        printZPL: (zpl, printer)   => enqueue({ data: zpl,   type: 'zpl',    printer, copies: 1 }),
        printESC: (escpos, printer) => enqueue({ data: escpos, type: 'escpos', printer, copies: 1 }),

        // Printer management
        setPrinter:          rememberPrinter,
        getPrinters:         async () => { await connectQZ(); return state.printers; },
        getCurrentPrinter:   () => state.currentPrinter,
        showPrinterSwitcher: () => openPrinterModal(null),

        // Device identity (UUID persisted per-browser/workstation)
        getDeviceId,

        // Connection
        connect:     connectQZ,
        disconnect:  () => window.qz ? qz.websocket.disconnect() : Promise.resolve(),
        isConnected: () => !!(window.qz && qz.websocket.isActive()),

        // Queue
        getQueue:    () => [...state.queue],
        clearQueue:  () => { state.queue = []; updateQueueUI(); emit('queue-cleared'); },

        // Settings
        getSettings:    () => ({ defaultPrinter: state.currentPrinter }),
        updateSettings: (s) => { if (s.defaultPrinter) rememberPrinter(s.defaultPrinter); emit('settings-updated', s); },

        // Events
        on:  (event, fn) => { state.listeners[event] = state.listeners[event] || []; state.listeners[event].push(fn); },
        off: (event, fn) => { state.listeners[event] = (state.listeners[event] || []).filter(f => f !== fn); },

        // Util
        retryOffline,
        retryJob,
        clearCache: () => {
            try {
                Object.keys(localStorage)
                    .filter(k => k.startsWith(STORAGE_PREFIX) || k === GLOBAL_KEY || k === 'sp_offline_queue')
                    .forEach(k => localStorage.removeItem(k));
            } catch (e) {}
            emit('cache-cleared');
        },
    };
})();

// ============================
// Global shorthand helpers
// ============================
function smartPrint(url, options) {
    return SmartPrint.print(url, options);
}
function smartPrintZPL(zpl, printer) {
    return SmartPrint.printZPL(zpl, printer);
}
function smartPrintESC(escpos, printer) {
    return SmartPrint.printESC(escpos, printer);
}
