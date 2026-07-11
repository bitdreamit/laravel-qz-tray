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
    let processingQueue  = false; // prevent concurrent processQueue calls

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
            fetch('/qz/certificate', { cache: 'no-store' })
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
                    'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
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
    function restorePrinter() {
        try {
            const saved = localStorage.getItem(STORAGE_PREFIX + pathKey())
                       || localStorage.getItem(GLOBAL_KEY);
            // Only restore if printer is in the current list (or list is empty = first connect)
            if (saved && (state.printers.length === 0 || state.printers.includes(saved))) {
                state.currentPrinter = saved;
            }
        } catch (e) {
            // localStorage may be unavailable (private browsing, etc.)
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
    function enqueue(job) {
        state.queue.push(job);
        updateQueueUI();
        emit('job-queued', { job });
        processQueue();
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
            const offline = JSON.parse(localStorage.getItem('sp_offline_queue') || '[]');
            offline.push(job);
            localStorage.setItem('sp_offline_queue', JSON.stringify(offline));
        } catch (e) {}
        emit('job-failed', { job });
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
    // Core print function
    // ============================
    async function printQZ(job) {
        const printer = job.printer || state.currentPrinter;

        if (!printer) {
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
                    console.error('[SmartPrint] PDF print requires a url.');
                    emit('job-failed', { job, error: new Error('Missing PDF url') });
                    return;
                }
                payload = [{ type: 'pdf', data: job.url }];
                break;
            case 'html':
                if (!job.data && !job.url) {
                    console.error('[SmartPrint] HTML print requires data or url.');
                    emit('job-failed', { job, error: new Error('Missing HTML data') });
                    return;
                }
                payload = [{ type: 'html', data: job.data || job.url }];
                break;
            case 'zpl':
            case 'raw':
            case 'escpos':
                if (!job.data) {
                    console.error('[SmartPrint] ' + job.type + ' print requires data.');
                    emit('job-failed', { job, error: new Error('Missing raw data') });
                    return;
                }
                payload = [{ type: 'raw', format: 'command', data: job.data }];
                break;
            default:
                fallback(job);
                return;
        }

        try {
            await qz.print(cfg, payload);
            emit('job-completed', { job });
        } catch (err) {
            console.error('[SmartPrint] Print error:', err);
            emit('job-failed', { job, error: err });
            fallback(job);
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
                    enqueue({ ...jobToQueue, printer });
                }
            };
        });

        modal.querySelector('#sp-modal-cancel').onclick = () => modal.remove();

        // Close on backdrop click
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
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
                enqueue({ url: urlOrOptions, type: 'pdf', copies: 1, ...options });
            } else {
                // Normalise copies to an integer so downstream code can rely on it.
                const job = { ...urlOrOptions };
                if (job.copies !== undefined) job.copies = parseInt(job.copies, 10) || 1;
                enqueue(job);
            }
        },
        printRaw: (data, type, printer) => enqueue({ data, type: type || 'raw', printer, copies: 1 }),
        printZPL: (zpl, printer)   => enqueue({ data: zpl,   type: 'zpl',    printer, copies: 1 }),
        printESC: (escpos, printer) => enqueue({ data: escpos, type: 'escpos', printer, copies: 1 }),

        // Printer management
        setPrinter:          rememberPrinter,
        getPrinters:         async () => { await connectQZ(); return state.printers; },
        getCurrentPrinter:   () => state.currentPrinter,
        showPrinterSwitcher: () => openPrinterModal(null),

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
