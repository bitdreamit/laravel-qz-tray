/**
 * SmartPrint — Laravel QZ Tray client library
 * Connects to QZ Tray for silent, dialog-free printing.
 *
 * Usage — four zero-config ways to print, pick whichever feels easiest:
 *
 *   1. class binding  — ANY button/link with class "smart-print" prints:
 *       <button class="smart-print" data-qz-url="/receipt/5.pdf">Print</button>
 *       <a class="smart-print" href="/receipt/5.pdf">Print receipt</a>
 *       <button class="smart-print" data-qz-urls="/a.pdf|/b.pdf|/c.pdf">Batch</button>
 *       <button class="smart-print" data-qz-element="#worklist-table">Worklist</button>
 *       <button class="smart-print" data-qz-html="&lt;h1&gt;Hello&lt;/h1&gt;">Hello</button>
 *
 *   2. onclick routing — the built-in functions are window globals, so a
 *      plain onclick works too (class or no class):
 *       <button onclick="printUrl('/receipt/5.pdf')">Print</button>
 *       <button onclick="printElement('#worklist-table', { copies: 2 })">…</button>
 *       <a href="#" onclick="printUrls(['/a.pdf','/b.pdf']); return false;">…</a>
 *      (An element that has BOTH class="smart-print" and its own onclick is
 *      left entirely to the onclick — never double-printed.)
 *
 *   3. named actions — <button data-qz-action="printLabReceipt" data-qz-url="/receipt/5.pdf">
 *   4. JS calls —     printUrl('/receipt/5.pdf');  smartPrint('/doc.pdf', {copies: 2});
 *
 *   <div data-qz-auto-print="/receipt/1.pdf" data-qz-delay="1000"></div>  auto on load
 *
 * v1.5 — Universal Print API: the generic names are BUILT-IN. No define()
 * needed — available as SmartPrint methods AND guarded window globals:
 *
 *   printUrl(url)                        any URL — PDF, image or HTML, the
 *                                       type is detected from Content-Type
 *   printUrls([url1, url2, url3])        sequential batch
 *   printPdf(x) / printPdfs([..])        URL | data: URI | raw base64
 *   printImage(x) / printImages([..])    image URL / data: URI / base64
 *   printHtml(html)                      HTML string | element | selector
 *   printElement('#id' | '.class')       any DOM element (styles cloned)
 *   printPage()                          the current page
 *   printUrl(url, { copies: 3 })         per-call overrides
 *
 * Works out of the box on Laravel routes that stream documents, including
 * mPDF ->stream() / ->download() behind auth middleware — v1.5 downloads
 * same-origin URLs through the PAGE first (session cookies included) and
 * hands QZ Tray the bytes, so the tray never needs its own session.
 *
 * Legacy names keep working: printLabReceipt / printLabReceipts are just
 * aliases of printUrl / printUrls (custom define() templates still win).
 *
 * Input flexibility — every function accepts:
 *   URL string | css selector (#id / .class / any) | HTMLElement | jQuery obj
 *   base64 / data: URI | {url|html|pdf|base64|selector|element|zpl|escpos|raw}
 *   () => any of the above (lazy)  |  array of any of the above (batch)
 *
 * Fallback engine — if QZ Tray is not installed / not connected / fails
 * mid-print, the SAME call degrades automatically (default: hidden-iframe
 * browser print; also 'window' | 'newtab' | 'download' | 'queue' | none |
 * custom function). Configure globally: window.QZ_CONFIG.fallbackMode.
 * Nothing throws unhandled; every promise resolves with a clear result.
 *
 * v1.5.4 — the fallback works on EVERY call, never only after a reload:
 *   • phones/tablets route through the new-tab engine (hidden-iframe
 *     window.print() is a no-op on iOS/Android) — the OS document viewer
 *     opens, with a programmatic-download and iframe safety net;
 *   • a hidden iframe that never fires `load` (blob: PDFs on mobile,
 *     stalled networks) is released by a watchdog, so the ONE serialized
 *     fallback queue can never deadlock;
 *   • the tray-connect wait is capped (QZ_CONFIG.connectTimeoutMs, default
 *     8000) — a hung wss://localhost handshake degrades to the browser
 *     instead of parking the first print forever;
 *   • every QZ print itself is time-capped too (QZ_CONFIG.printTimeoutMs,
 *     default 25000) — a tray socket that opened but never answers can no
 *     longer hold the print queue hostage; the browser fallback fires
 *     WITHOUT a page reload and later prints keep working;
 *   • a mid-handshake socket is no longer "adopted" as connected — the
 *     phantom connected state (and the phantom /qz/printer fetches) that
 *     routed prints into a dead socket is gone;
 *   • machines without the tray: auto-reconnect scans ONE port set per
 *     tick and backs off 10s → 5min instead of hammering every candidate
 *     socket twice every 10 seconds forever.
 *
 * v1.5.5 — the tray is contacted ONLY when needed ("why it call again and
 * again" is gone):
 *   • LAZY connect: a page load performs NO connection attempt, NO port
 *     scan and NO /qz/printer fetch. The FIRST print — or Ctrl+Shift+Q —
 *     connects. Opt into the old connect-on-load with
 *     window.QZ_CONFIG.connectOnInit = true;
 *   • after one failed scan the tray is marked unavailable for a 60s
 *     cooldown (QZ_CONFIG.unavailableCooldownMs): every print in that
 *     window goes STRAIGHT to the browser fallback — no reload needed,
 *     no reconnect storm, no repeated /qz/printer fetches. When the
 *     cooldown expires the NEXT print probes the tray once more, so
 *     starting QZ Tray mid-session silently re-enables direct printing;
 *   • background auto-reconnect is OPT-IN now (QZ_CONFIG.autoReconnect =
 *     true restores the 10s → 5min ladder for live status pages);
 *   • Ctrl+Shift+Q anywhere = instant connection check (toast + console:
 *     "QZ Tray connected — N printers · using X" or "QZ Tray NOT running
 *     — printing via the browser dialog"). Disable/override with
 *     window.QZ_CONFIG.connectionHotkey.
 *   • every knob above lives in ONE table — QZ_DEFAULTS at the top of this
 *     file, applied through a single qzCfg() accessor; window.QZ_CONFIG
 *     overrides per key. SmartPrint.config() returns the effective merged
 *     values for the current page, so "what can I switch?" is always one
 *     console call away.
 *
 * Silent by default (v1.5.0) — no "Select Printer" modal, no qz:launch
 * protocol prompt, no install alerts. With no printer remembered the OS
 * DEFAULT printer prints. Opt back in per page:
 *   window.QZ_CONFIG.printerPrompt  = true   (auto "Select Printer" modal)
 *   window.QZ_CONFIG.launchProtocol = true   (qz:launch tray-start attempt)
 *
 * Extras: SmartPrint.aliasPrinter('receipt', 'XP-80C'), SmartPrint.status(),
 * SmartPrint.whenReady(3000), printer-alias resolution at print time,
 * PDF base64 printing without a URL, stylesheets cloned into element
 * printouts, and data-qz-action buttons.
 */
window.SmartPrint = (() => {
    const STORAGE_PREFIX = 'smart_printer:';
    const GLOBAL_KEY     = 'smart_printer_global';
    const DEVICE_ID_KEY  = 'smart_print_device_id';
    let processingQueue  = false; // prevent concurrent processQueue calls

    // ============================================================
    // QZ_CONFIG — THE one section that controls everything (v1.5.5)
    // ============================================================
    // Every option this library understands lives in THIS table. Set any
    // subset of them on the page, BEFORE smart-print.js loads:
    //
    //   <script>
    //     window.QZ_CONFIG = {
    //         connectOnInit: true,            // scan the tray on page load
    //         autoReconnect: true,            // background 10s→5min ladder
    //         connectionHotkey: { enabled: false },
    //     };
    //   </script>
    //   <script src="/vendor/qz-tray/js/smart-print.js?v=..."></script>
    //
    // Defaults are SILENT-BY-DEFAULT: a page that sets nothing performs
    // ZERO localhost calls on load (no wss://localhost scan, no /qz/*
    // fetch), ZERO reconnect attempts when the tray is absent, ZERO
    // dialogs and ZERO console errors. The tray is contacted only when a
    // print actually needs it (or Ctrl+Shift+Q is pressed), and one failed
    // scan arms a 60s cooldown during which every print goes straight to
    // the browser — no reload, no storm. Every switch you can flip:
    //
    //   CONNECTION (all off / silent by default)
    //   connectOnInit          false   probe the tray when the page loads
    //                                  (pre-1.5.5 behavior; lazy is default)
    //   autoReconnect          false   background reconnect ladder 10s→5min
    //   launchProtocol         false   try qz:launch after a failed scan
    //                                  (pops a browser prompt — opt-in!)
    //   unavailableCooldownMs  60000   ms prints skip a dead tray after one
    //                                  failed scan (0 disables the cooldown)
    //   connectTimeoutMs        8000   cap for ONE connect attempt per print
    //   connectRetryDelayMs     1500   nap between retries inside an attempt
    //
    //   PRINT PIPELINE
    //   printTimeoutMs         25000   cap for ONE QZ print (watchdog)
    //   fallbackMode           'auto'  'auto'|'iframe'|'window'|'newtab'
    //                                  |'download'|'queue'|'none'|false|fn
    //   mobileFallbackMode     null    phones/tablets override (default auto
    //                                  routes them through 'newtab')
    //   fetchMode              'auto'  hydration: 'auto'|'always'|'never'|false
    //   fallbackLoadWatchdogMs 20000   iframe load watchdog for fallbacks
    //   notify                 false   toast when a job degrades to browser
    //   onFallback             null    function(job) on every fallback
    //   printerPrompt          false   auto "Select Printer" modal before print
    //
    //   HOTKEYS (Ctrl+Shift+Q = tray connection check, v1.5.5)
    //   connectionHotkey       { enabled: true, combination: 'ctrl+shift+q' }
    //                                  the manual probe + status toast;
    //                                  also doubles as "reconnect NOW" — it
    //                                  clears the unavailable cooldown
    //   hotkey                 { enabled: true, combination: 'ctrl+shift+p' }
    //                                  opens the printer switcher modal
    //
    //   SERVER / IDENTITY
    //   serverSync             true    remember printer/log jobs server-side
    //   prefix                 null    route prefix (default '/qz')
    //   endpoints              null    { certificate, sign, print, ... }
    //   assetsBase             '/vendor/qz-tray/js'
    //   tenantId / projectId   undefined  page-wide tenant scoping
    //   uuidVersion            'v7'    job id flavor ('v4' opts out)
    //   observeDom             false   re-scan DOM for new .smart-print nodes
    // ============================================================
    const QZ_DEFAULTS = {
        // connection
        connectOnInit:          false,
        autoReconnect:          false,
        launchProtocol:         false,
        unavailableCooldownMs:  60000,
        connectTimeoutMs:       8000,
        connectRetryDelayMs:    1500,
        // print pipeline
        printTimeoutMs:         25000,
        fallbackMode:           'auto',
        mobileFallbackMode:     undefined,
        fetchMode:              'auto',
        fallbackLoadWatchdogMs: 20000,
        notify:                 false,
        onFallback:             null,
        printerPrompt:          false,
        // hotkeys
        connectionHotkey:       { enabled: true, combination: 'ctrl+shift+q' },
        hotkey:                 { enabled: true, combination: 'ctrl+shift+p' },
        // server / identity
        serverSync:             true,
        prefix:                 null,
        endpoints:              null,
        assetsBase:             '/vendor/qz-tray/js',
        tenantId:               undefined,
        projectId:              undefined,
        uuidVersion:            'v7',
        observeDom:             false,
    };

    // Single accessor for every option read below — window.QZ_CONFIG wins
    // per key, QZ_DEFAULTS fills the rest. Nothing else in this file should
    // poke window.QZ_CONFIG directly, so the table above stays the one
    // authoritative list of knobs and their silent-by-default values.
    function qzCfg(key) {
        const user = (typeof window !== 'undefined' && window.QZ_CONFIG) || {};
        return user[key] !== undefined ? user[key] : QZ_DEFAULTS[key];
    }

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
        if (qzCfg('uuidVersion') === 'v4') {
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

    // v1.5.0: Laravel also accepts the encrypted XSRF-TOKEN cookie as a CSRF
    // credential (header X-XSRF-TOKEN). The cookie survives much longer than
    // a hard-coded meta tag on a page the user kept open for hours, so
    // sending BOTH fixes the "POST /qz/print 419" seen on long-lived tabs
    // and layouts that forget the csrf meta tag entirely.
    function xsrfCookie() {
        try {
            const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
            return m ? decodeURIComponent(m[1]) : '';
        } catch (e) { return ''; }
    }

    // ============================
    // HTML escaping (AUDIT C3)
    // ============================
    // Printer names come from the OS (and can originate a malicious network
    // share); job URLs can be built from user input. Anything interpolated
    // into HTML must pass through here.
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ============================
    // Endpoint configuration (AUDIT H2)
    // ============================
    // The route prefix is server-configurable via config('qz-tray.routes.prefix'),
    // but every fetch below hardcoded '/qz/...' — changing the prefix
    // silently broke certificate retrieval, signing, and printer memory.
    // A host app can now override once, before including this file:
    //   window.QZ_CONFIG = { prefix: 'printing' };
    // or supply absolute URLs per endpoint:
    //   window.QZ_CONFIG = { endpoints: { certificate: '/printing/certificate', ... } };
    function apiBase() {
        const prefix = qzCfg('prefix');
        if (prefix !== undefined && prefix !== null) {
            return '/' + String(prefix).replace(/^\/+|\/+$/g, '');
        }
        return '/qz';
    }

    function endpoint(name) {
        const overrides = qzCfg('endpoints');
        const defaults = {
            certificate: apiBase() + '/certificate',
            sign:        apiBase() + '/sign',
            printerSet:  apiBase() + '/printer',
            printerGet:  apiBase() + '/printer',
            print:       apiBase() + '/print',
            jobs:        apiBase() + '/jobs',
        };
        return (overrides && overrides[name]) || defaults[name];
    }

    // Server sync is opt-out via window.QZ_CONFIG.serverSync = false, for
    // deployments that only ever want the localStorage-only behavior of
    // pre-1.1 releases.
    function serverSyncEnabled() {
        return qzCfg('serverSync') !== false;
    }

    const state = {
        qzReady:      false,
        connecting:   false,
        connectPromise: null,
        launchAttempts: 0,   // AUDIT H7: cap qz:launch protocol attempts per page load
        printers:     [],
        currentPrinter: null,
        _lastSyncedKey: null, // AUDIT M6: suppress redundant server syncs
        _jobLogBlocked: false, // v1.5.0: stop logging after 419/401 (token/session dead)
        _lastReconnectTry: 0,  // v1.5.0: reconnect backoff clock
        _serverRestoreTried: false, // v1.5.4: /qz/printer GET once per page, not per connect
        _defaultPrinter: null, // v1.5.0: OS default printer name, resolved once per connection
        _adoptPromise: null,   // v1.5.0: in-flight adoptActiveConnection() — no double discovery
        qzUnavailableUntil: 0, // v1.5.5: failed-scan cooldown — prints skip the tray until it expires
        _offlineRetried: false, // v1.5.5: offline queue retried once on the first successful connect
        _cooldownLogged: false, // v1.5.5: the "unavailable" console note shows once per cooldown window
        queue:        [],
        failedQueue:  [],
        listeners:    {},
        channel:      (typeof BroadcastChannel !== 'undefined')
                        ? new BroadcastChannel('smart-print')
                        : null,
    };

    // Path key: use full pathname for per-page printer memory
    const pathKey = () => location.pathname;

    // v1.5.0 single-flight connect shim. Host apps frequently ship their OWN
    // legacy QZ bootstrap (app.js calling qz.websocket.connect() on load, with
    // its own certificate/sign promises). Two uncoordinated connect paths
    // churn the same socket — the handshake fails with "Failed to get
    // certificate" and prints throw "sendData is not a function". Routing
    // every PLAIN connect() call through this module's single-flight attempt
    // means smart-print's security setup is always in force and only ONE
    // socket ever exists. Custom host/port options still pass through native
    // (v1.5.0: but only after re-arming OUR certificate/sign resolvers —
    // legacy bootstraps open those sockets before setupSecurity ever ran,
    // and QZ Tray's first challenge then rejected with bare "undefined").
    // attemptConnect() bypasses the shim via nativeWsConnect — otherwise it
    // would await its own connectPromise (a self-deadlock).
    let nativeWsConnect = null;
    if (typeof window !== 'undefined' && window.qz && qz.websocket) {
        nativeWsConnect = qz.websocket.connect.bind(qz.websocket);
        qz.websocket.connect = function (opts) {
            if (opts && typeof opts === 'object' && Object.keys(opts).length) {
                try { setupSecurity(); } catch (e) {}   // never ship an unregistered cert resolver
                return nativeWsConnect(opts);
            }
            return connectQZ(1);
        };
    }

    // ============================
    // QZ-unavailable cooldown (v1.5.5)
    // ============================
    // One failed tray scan used to be followed by ANOTHER scan on every
    // print, every background tick and every phantom adoption — the
    // "why it call again and again" console storm. After a scan gives up
    // the tray is marked unavailable for a cooldown window: every print in
    // that window goes STRAIGHT to the browser fallback (zero scans, zero
    // /qz/printer fetches, zero console errors — and never a reload).
    // When the window expires the NEXT print probes the tray once more, so
    // starting QZ Tray mid-session silently re-enables direct printing.
    function unavailableCooldownMs() {
        const cfg = parseInt(qzCfg('unavailableCooldownMs'), 10);
        return cfg > 0 ? cfg : 60000;   // 60s default
    }
    function markQzUnavailable() {
        state.qzUnavailableUntil = Date.now() + unavailableCooldownMs();
        emit('qz-unavailable', { cooldownMs: unavailableCooldownMs() });
    }
    function clearQzUnavailable() {
        state.qzUnavailableUntil = 0;
        state._cooldownLogged = false;
    }
    function qzInCooldown() {
        return Date.now() < state.qzUnavailableUntil;
    }
    // One quiet console note per cooldown window — never one line per print.
    function cooldownSkipNote() {
        if (state._cooldownLogged) return;
        state._cooldownLogged = true;
        console.info('[SmartPrint] QZ Tray unavailable (failed scan, ' + Math.round(unavailableCooldownMs() / 1000)
            + 's cooldown) — printing via the browser. Press Ctrl+Shift+Q to re-check the tray.');
        setTimeout(() => { state._cooldownLogged = false; }, unavailableCooldownMs());
    }
    // v1.5.5: connect-on-load is OPT-IN. The default page load performs NO
    // connection attempt at all; the first print (or Ctrl+Shift+Q) connects.
    function resolveConnectOnInit() {
        return qzCfg('connectOnInit') === true;
    }
    // v1.5.5: parked offline jobs retry on the FIRST successful connection —
    // with lazy connect there is no load-time connect to piggyback on anymore.
    // (retryOffline is hoisted — defined with the queue management section.)
    function retryOfflineOnceAfterConnect() {
        if (state._offlineRetried) return;
        state._offlineRetried = true;
        retryOffline();
    }

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

        qz.security.setCertificatePromise((resolve, reject) =>
            fetch(endpoint('certificate'), {
                cache: 'no-store',
                headers: { 'X-Device-Id': getDeviceId() },
            })
                .then(r => {
                    if (!r.ok) throw new Error('Certificate fetch failed: HTTP ' + r.status);
                    return r.text();
                })
                .then(resolve)
                // v1.5.0: never let the rejection reason be bare undefined —
                // qz-tray logs "Failed to get certificate: undefined" and the
                // root cause becomes untraceable. Surface the real error.
                .catch(reject)
        );

        qz.security.setSignatureAlgorithm('SHA512');

        qz.security.setSignaturePromise(toSign => (resolve, reject) =>
            fetch(endpoint('sign'), {
                method: 'POST',
                cache:  'no-store',
                headers: {
                    'Content-Type':  'application/json',
                    'X-CSRF-TOKEN':  csrfToken(),
                    'X-XSRF-TOKEN':  xsrfCookie(), // v1.5.0: works even without the meta tag
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
    // AUDIT H6: concurrent callers used to poll qz.websocket.isActive()
    // after a blind 200ms nap — far shorter than a real handshake — so a
    // caller arriving during a slow connect received `false`, pushed its job
    // to the offline buffer, and printed a duplicate when retrying. All
    // callers now share the same in-flight attempt via state.connectPromise.
    //
    // v1.5.4: withTimeout() — qz-tray.js has NO call timeout of its own. A
    // socket that opens but never answers (phone handshake, auth stuck,
    // dying tray) leaves find()/adoption promises pending forever, which is
    // how a print queue ends up blocked until a page reload. Everything we
    // await below is capped.
    function withTimeout(promise, ms, timeoutValue) {
        let t;
        const cap = new Promise(res => { t = setTimeout(() => res(timeoutValue), ms); });
        return Promise.race([promise, cap]).finally(() => clearTimeout(t));
    }

    async function attemptConnect(retries) {
        try {
            await (nativeWsConnect ? nativeWsConnect() : qz.websocket.connect());
            state.qzReady = true;
            try {
                // 5s cap: a connected-but-unresponsive tray must not hang the
                // whole connect chain — zero printers is the safe outcome.
                state.printers = await withTimeout(qz.printers.find(), 5000, []);
            } catch (listErr) {
                // A connected Tray with zero usable system printers still
                // counts as connected; find() can throw instead of [].
                state.printers = [];
            }
            restorePrinter();
            clearQzUnavailable();            // v1.5.5: a live tray clears the cooldown
            retryOfflineOnceAfterConnect();  // v1.5.5: parked jobs ride the first success
            emit('connected', { printers: state.printers });
            emit('printers-loaded', { printers: state.printers });
            return true;
        } catch (err) {
            // AUDIT H7: qz:launch used to fire on every retry AND on every
            // 10s auto-reconnect tick, so a machine without QZ Tray saw a
            // native protocol dialog roughly every 10 seconds (Firefox
            // prompts each time). Attempt the protocol launch at most twice
            // per page load, then reconnect silently.
            if (retries > 0 && state.launchAttempts < 2) {
                state.launchAttempts++;
                // v1.5.0: the qz:launch protocol attempt makes Firefox (and
                // some Chrome builds) pop a native "Open QZ Tray?" / "You'll
                // need a new app to open this qz link" dialog — an install
                // alert kiosk users must never see. Off by default now;
                // opt in per page with window.QZ_CONFIG.launchProtocol = true.
                if (qzCfg('launchProtocol')) {
                    try { launchQZProtocol(); } catch (_) {}
                }
                // v1.5.5: retry nap is configurable (kiosks can go 0; the
                // smoke tests do) — default stays 1500ms.
                const napCfg = parseInt(qzCfg('connectRetryDelayMs'), 10);
                await new Promise(r => setTimeout(r, napCfg >= 0 ? napCfg : 1500));
                return attemptConnect(retries - 1);
            }
            state.qzReady = false;
            // v1.5.5: ONE failed scan is enough. Arm the cooldown so the next
            // prints skip the tray entirely instead of rescanning (and
            // re-fetching /qz/printer) on every click.
            markQzUnavailable();
            emit('connection-failed', { error: err });
            return false;
        }
    }

    // v1.5.0: ADOPT a socket somebody else opened. Legacy app.js bootstraps
    // call qz.websocket.connect() directly (sometimes with custom host/port
    // opts that bypass the single-flight shim) — frequently BEFORE
    // DOMContentLoaded, i.e. before init()/connectQZ() ever ran. The socket
    // then exists, authenticated even, but SmartPrint stayed blind:
    // state.printers stayed [], 'connected' never fired, getPrinters()
    // returned [] while qz.printers.find() clearly worked. Adoption runs the
    // discovery + restore + event flow for such sockets, once.
    function adoptActiveConnection() {
        if (!window.qz || !qz.websocket.isActive()) return Promise.resolve(false);
        if (state.printers.length) return Promise.resolve(true);   // already ours
        if (state._adoptPromise) return state._adoptPromise;

        state._adoptPromise = (async () => {
            try { setupSecurity(); } catch (e) {}   // re-assert OUR resolvers for future challenges
            // v1.5.4: qz.websocket.isActive() ALSO reports true while the
            // socket is still CONNECTING — a legacy app.js bootstrap's
            // handshake in flight looks exactly like a live tray. Discovery
            // on such a socket throws instantly on desktop and hangs on
            // phones, and the old code treated BOTH as "connected":
            // qzReady flipped true, a phantom 'connected' fired,
            // restorePrinter() re-fetched /qz/printer, and the next print
            // was routed into a dead socket instead of the browser
            // fallback. Adopt ONLY when discovery actually answers (5s
            // cap); a mid-handshake socket stays unadopted and the caller
            // degrades to the fallback it should have used.
            const found = await withTimeout(qz.printers.find().catch(() => null), 5000, null);
            if (found === null) {
                console.info('[SmartPrint] socket found mid-handshake — not adopting yet; prints use the browser fallback until the tray answers.');
                return false;
            }
            state.printers = found;   // connected Tray with zero usable printers still counts ([])
            state.qzReady = true;
            try { restorePrinter(); } catch (e) {}
            clearQzUnavailable();            // v1.5.5
            retryOfflineOnceAfterConnect();  // v1.5.5
            emit('connected', { printers: state.printers });
            emit('printers-loaded', { printers: state.printers });
            return true;
        })().finally(() => { state._adoptPromise = null; });

        return state._adoptPromise;
    }

    // v1.5.4: default retries 2 → 1. Each retry is a FULL port scan (8
    // candidate sockets in qz-tray.js) + a 1.5s nap; a machine without the
    // tray used to burn three scans (~24 failed sockets, ~3-4s) before the
    // first print ever reached the browser fallback. One retry keeps the
    // "tray woke up late" recovery while halving the dead time and the
    // console noise. Background ticks pass 0 (one scan, see auto-reconnect).
    async function connectQZ(retries = 1) {
        if (!window.qz) {
            console.warn('[SmartPrint] QZ Tray library not loaded. Add <script src="' +
                qzCfg('assetsBase') + '/qz-tray.min.js"></script> to your page (before smart-print.js).');
            emit('init-failed', { reason: 'qz-library-missing' });
            return false;
        }

        if (qz.websocket.isActive()) return adoptActiveConnection();

        if (state.connectPromise) {
            return state.connectPromise;
        }

        state.connecting = true;
        setupSecurity();
        state.connectPromise = attemptConnect(retries).finally(() => {
            state.connecting = false;
            state.connectPromise = null;
        });

        return state.connectPromise;
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
        const tenant = qzCfg('tenantId');
        return (tenant !== undefined) ? tenant : qzCfg('projectId');
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
        //
        // v1.5.4: the round-trip runs ONCE per page. The old code fired it
        // on every (re)connect, so a machine without the tray — where every
        // phantom/false adoption re-entered here — spammed
        // GET /qz/printer?path=… on the server log for nothing.
        if (!saved && serverSyncEnabled() && !state._serverRestoreTried) {
            state._serverRestoreTried = true;
            const tenantId = pageTenantId();
            // AUDIT H3: send the page path as ?path= instead of a URL-encoded
            // path segment. encodeURIComponent(pathname) produces %2F for
            // every slash, and Apache rejects %2F by default (404). The
            // query-param route works on every web server.
            const qs = tenantId ? ('&tenant_id=' + encodeURIComponent(tenantId)) : '';
            fetch(endpoint('printerGet') + '?path=' + encodeURIComponent(pathKey()) + qs, {
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

        // AUDIT M6: printQZ() calls rememberPrinter() on EVERY print, and the
        // old code POSTed to the server each time — four identical writes for
        // four receipts on one page. Only sync when the choice actually
        // changed for this scope (or the scope itself changed).
        const syncKey = scope + ':' + printer;
        if (serverSyncEnabled() && state._lastSyncedKey !== syncKey) {
            state._lastSyncedKey = syncKey;
            const tenantId = pageTenantId();
            fetch(endpoint('printerSet'), {
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
                // v1.5.4: the connect attempt used to be awaited UNCONDITIONALLY.
                // On mobile browsers the wss://localhost:8181 handshake can HANG
                // instead of refusing (and attemptConnect retries twice on top),
                // so the first print click waited forever with no fallback, no
                // error — a page reload only "fixed" it because it reset the
                // state. Cap the wait: after the cap the job degrades to the
                // browser fallback while the background attempt keeps running —
                // if the tray comes up later, the NEXT print is silent again.
                const capCfg = parseInt(qzCfg('connectTimeoutMs'), 10);
                const connectCap = capCfg > 0 ? capCfg : 8000;
                let capTimer;
                let connected = false;
                // v1.5.5: an active socket is always usable; otherwise an
                // armed cooldown SKIPS the scan entirely — the job prints via
                // the browser without a single reconnect attempt, fetch or
                // console error. This is the "no more call again and again"
                // gate.
                if ((window.qz && qz.websocket.isActive()) || !qzInCooldown()) {
                    connected = await Promise.race([
                        connectQZ(),
                        new Promise(res => { capTimer = setTimeout(() => res(false), connectCap); }),
                    ]).finally(() => clearTimeout(capTimer));
                    // Hung handshake (mobile wss://localhost, dying tray): the
                    // scan never settled, so the NEXT print must not wait for
                    // another one — arm the cooldown here as well.
                    if (!connected && window.qz && (state.connecting || state.connectPromise)) {
                        markQzUnavailable();
                    }
                } else {
                    cooldownSkipNote();
                }

                if (connected) {
                    // v1.5.4: PRINT WATCHDOG. A tray socket that opened but
                    // never answers (hung handshake half-finished, auth
                    // stuck, dead spooler) used to leave await printQZ(job)
                    // pending FOREVER: the queue mutex below never released,
                    // every later print silently piled up in state.queue,
                    // and only a full page reload printed again. Cap the
                    // whole QZ attempt — on timeout degrade to the browser
                    // fallback, release the socket, and keep the queue
                    // alive. Configurable via QZ_CONFIG.printTimeoutMs.
                    const ptCfg = parseInt(qzCfg('printTimeoutMs'), 10);
                    const printCap = ptCfg > 0 ? ptCfg : 25000;
                    let printTimer;
                    const outcome = await Promise.race([
                        printQZ(job),
                        new Promise(res => { printTimer = setTimeout(() => res('<<qz-watchdog>>'), printCap); }),
                    ]).finally(() => clearTimeout(printTimer));

                    if (outcome === '<<qz-watchdog>>') {
                        console.warn('[SmartPrint] QZ print did not settle within ' + printCap +
                            'ms — degrading to the browser fallback (no reload needed).');
                        try { qz.websocket.close(); } catch (_) {}
                        state.qzReady = false;
                        state.printers = [];
                        state._defaultPrinter = null;
                        if (dispatchFallback(job)) {
                            notifyFallback(job);
                            emit('job-failed', { job, fallback: true, reason: 'qz-timeout' });
                            job._resolve && job._resolve({ jobId: job.id, success: false, fallback: true, reason: 'qz-timeout' });
                        } else {
                            offlineBuffer(job);
                        }
                    }
                } else {
                    if (state.connecting) {
                        console.info('[SmartPrint] tray connection still pending after ' + connectCap +
                            'ms — printing via the browser fallback; the tray can still connect for later prints.');
                    }
                    handleNoConnection(job);
                }
            } catch (err) {
                console.error('[SmartPrint] Job error:', err);
                offlineBuffer(job);
            }
        }

        processingQueue = false;
        updateQueueUI();
    }

    // v1.5 — optional user-visible notice + hook when a job degrades to the
    // browser. Silent by default (QZ_CONFIG.notify = true enables the toast;
    // QZ_CONFIG.onFallback = fn receives the job) so business flows are
    // never interrupted by UI noise.
    function notifyFallback(job) {
        const onFallback = qzCfg('onFallback');
        if (typeof onFallback === 'function') {
            try { onFallback(job); } catch (e) { console.error('[SmartPrint] onFallback error:', e); }
        }
        if (qzCfg('notify')) toastNotice('QZ Tray unavailable — printed via browser');
    }

    function toastNotice(msg) {
        try {
            let t = document.getElementById('sp-toast');
            if (!t) {
                t = document.createElement('div');
                t.id = 'sp-toast';
                t.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:99999;'
                    + 'background:#333;color:#fff;padding:10px 14px;border-radius:6px;'
                    + 'font:13px system-ui,sans-serif;opacity:0;transition:opacity .3s;'
                    + 'pointer-events:none;box-shadow:0 4px 12px rgba(0,0,0,.25)';
                document.body.appendChild(t);
            }
            t.textContent = msg;   // textContent — never HTML
            t.style.opacity = '1';
            clearTimeout(t._spTimer);
            t._spTimer = setTimeout(() => { t.style.opacity = '0'; }, 3500);
        } catch (e) {}
    }

    // v1.5: QZ Tray not installed / not running / refused the connection.
    // The pre-1.5 behavior parked the job in the offline retry queue; with
    // fallbackMode 'auto' (the default) printable jobs degrade to the
    // browser immediately so a machine without the tray still prints. The
    // old behavior remains available via QZ_CONFIG.fallbackMode = 'queue'.
    function handleNoConnection(job) {
        const mode = resolveFallbackMode(job);
        // v1.5.4: 'image' is printable by the browser engines too
        // (fallbackIframe wraps it in an <img> document) — it used to be
        // parked in the offline queue with a "QZ Tray offline" error on
        // phones / tray-less machines instead of just printing.
        const printable = job.type === 'pdf' || job.type === 'html' || job.type === 'image';

        if (!printable || mode === 'queue' || mode === 'offline' || mode === 'none' || mode === false) {
            return offlineBuffer(job);
        }

        const printed = dispatchFallback(job);
        if (printed) {
            notifyFallback(job);
            emit('job-failed', { job, fallback: true, reason: 'qz-unavailable' });
            job._resolve && job._resolve({ jobId: job.id, success: false, fallback: true, reason: 'qz-unavailable' });
        } else {
            offlineBuffer(job);
        }
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
    // Lifecycle (AUDIT C2): the client reports 'processing' when the job
    // starts and 'completed'/'failed' when qz.print() settles. POST /qz/print
    // is an idempotent upsert on the client job id (v1.2.1), so the repeat
    // reports no longer crash into a duplicate-primary-key error (uuid mode)
    // or fork a second row (bigint mode) — the old behavior left every row
    // stuck at 'pending' forever and made the queue endpoints useless.
    function logPrintJob(job, printer, status, errorMessage) {
        if (!serverSyncEnabled() || state._jobLogBlocked) return;
        // Per-job value wins; otherwise fall back to a page-wide default set
        // by the host app (e.g. window.QZ_CONFIG.tenantId = '{{ $project->id }}'
        // — works whether that id is a bigint or a uuid string).
        const tenantId = job.tenantId ?? job.projectId ?? pageTenantId() ?? undefined;

        // v1.5.0: the server caps url at 2048 chars and error_message at 1000
        // (QzSecurityController::print validation). A URL that carries a PDF
        // payload (blade passing base64 as the receipt id) or any mega URL
        // must not 422 the logger — truncate oversized fields and count the
        // skipped payloads in metadata instead.
        const MAX_LOG_URL   = 240;    // fits ANY server cap (smallest known: max:255)
        const MAX_LOG_ERROR = 240;
        const MAX_LOG_DATA  = 65536;   // keep well under a TEXT column limit
        let logUrl = job.url || undefined;
        if (typeof logUrl === 'string' && logUrl.length > MAX_LOG_URL) {
            // marker included INSIDE the cap so the total stays <= MAX_LOG_URL
            const marker = '…[truncated:' + job.url.length + ' chars]';
            logUrl = job.url.slice(0, MAX_LOG_URL - marker.length) + marker;
        }
        const rawLogData = job.url ? undefined : (job.data || undefined);
        const logData = (typeof rawLogData === 'string' && rawLogData.length > MAX_LOG_DATA)
            ? undefined : rawLogData;
        const logError = (typeof errorMessage === 'string' && errorMessage.length > MAX_LOG_ERROR)
            ? errorMessage.slice(0, MAX_LOG_ERROR) : (errorMessage || undefined);
        const meta = { status: status || 'completed' };
        if (job.url && logUrl !== job.url) meta.url_truncated = true;
        if (rawLogData !== undefined && logData === undefined) meta.data_bytes = rawLogData.length;

        fetch(endpoint('print'), {
            method: 'POST',
            cache:  'no-store',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-XSRF-TOKEN': xsrfCookie(),   // v1.5.0: cookie survives a stale meta tag
                'X-Device-Id':  getDeviceId(),
                'Accept':       'application/json',
            },
            body: JSON.stringify({
                job_id:    job.id,
                printer,
                type:      job.type,
                url:       logUrl,
                data:      logData,
                copies:    job.copies,
                device_id: getDeviceId(),
                tenant_id: tenantId !== undefined ? String(tenantId) : undefined,
                status,
                error_message: logError,
                metadata:  meta,
            }),
        }).then(r => {
            // v1.5.0: 419/401 = session/token dead (long-lived tab, logged out
            // elsewhere). Job logging is best-effort — flip the flag so the
            // page stops spraying 419s on every print; printing continues.
            if (r && (r.status === 419 || r.status === 401)) state._jobLogBlocked = true;
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
        // v1.5: printer names may be aliases registered via
        // SmartPrint.aliasPrinter('receipt', 'XP-80C') — resolve at print
        // time so an alias defined after the job was queued still wins.
        // v1.5.4 CRITICAL fix: this was `const` — and the OS-default-printer
        // branch below REASSIGNS it, so every print with no remembered
        // printer threw "TypeError: Assignment to constant variable", got
        // swallowed by processQueue's catch, and the job was parked in the
        // offline queue instead of printing (or falling back). That is the
        // "print fails on Windows / phone — no print until reload" report:
        // fresh browser profiles, cleared localStorage, a vanished printer
        // or a pending server restore all land on the no-remembered-printer
        // path. `let` lets the default-printer resolution actually work.
        let printer = resolveAlias(job.printer) || state.currentPrinter;

        if (!printer) {
            // The old flow parked every job behind the "Select Printer"
            // modal, which lab/kiosk users read as an error — and when the
            // tray had no printer list it shouted "No printers found. Is
            // QZ Tray running?". The modal is now opt-in per page:
            //   window.QZ_CONFIG.printerPrompt = true    (auto-ask again)
            //   SmartPrint.showPrinterSwitcher()         (your own button)
            if (qzCfg('printerPrompt')) {
                // Promise stays pending — resolved/rejected once the user
                // answers the printer-selection modal (see openPrinterModal).
                openPrinterModal(job);
                return;
            }

            // v1.5.0: no remembered printer -> print to the OS DEFAULT
            // printer. QZ Tray 2.2 REJECTS an empty printer name with
            // "Error: A printer must be specified before printing", so the
            // default printer's real NAME must be resolved via
            // qz.printers.getDefault() (QZ Tray 2.1+) once per connection.
            try {
                if (!state._defaultPrinter) {
                    state._defaultPrinter = await qz.printers.getDefault();
                }
                printer = state._defaultPrinter;
            } catch (_) { /* older tray / no default — fall through */ }

            if (!printer && state.printers && state.printers.length) {
                printer = state.printers[0]; // last resort: first known printer
            }

            if (!printer) {
                // Tray answered but no usable printer is known — the browser
                // fallback is the honest result.
                const printed = dispatchFallback(job);
                if (printed) {
                    notifyFallback(job);
                    job._resolve && job._resolve({ jobId: job.id, success: false, fallback: true, reason: 'no-printer' });
                } else {
                    offlineBuffer(job);
                }
                return;
            }
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
                if (!job.url && !job.data) {
                    const err = new Error('Missing PDF url or base64 data');
                    console.error('[SmartPrint] PDF print requires a url or base64 data.');
                    emit('job-failed', { job, error: err });
                    safeCallback(job.onError, err, job);
                    job._reject && job._reject(err);
                    return;
                }
                // v1.5: QZ Tray accepts BOTH a URL and base64-encoded bytes in
                // the pdf payload — direct base64 printing (fetched blobs,
                // canvas exports, already-downloaded documents) no longer
                // needs a round-trip to a public URL.
                // v1.5.4: LAST-LINE rescue — a URL that carries a %PDF-
                // payload (base64 pasted as a URL, blade passing it as an id)
                // must never reach QZ as (FILE): servers answer 414. Convert
                // to the base64 bytes instead. Also absolutize relative URLs
                // — QZ cannot resolve them ("could not be found").
                let pdfRef = job.data || (job.url ? absolutizeForQZ(job.url) : undefined);
                if (!job.data && job.url) {
                    const embedded = embeddedPdfInUrl(job.url);
                    if (embedded) {
                        console.warn('[SmartPrint] print URL carried an embedded PDF payload ('
                            + Math.round(embedded.length / 1024) + ' KB base64) — printing the bytes directly.');
                        pdfRef = embedded;
                    }
                }
                payload = [{ type: 'pdf', data: pdfRef }];
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
                payload = [{ type: 'html', data: job.data || absolutizeForQZ(job.url) }];
                break;
            case 'image':
                // v1.5: native QZ image payload — base64 (hydrated bytes) or
                // a direct URL (public URLs only; QZ fetches those itself).
                if (!job.data && !job.url) {
                    const err = new Error('Missing image data or url');
                    console.error('[SmartPrint] Image print requires data or url.');
                    emit('job-failed', { job, error: err });
                    safeCallback(job.onError, err, job);
                    job._reject && job._reject(err);
                    return;
                }
                payload = job.data
                    ? [{ type: 'image', format: 'base64', data: job.data }]
                    : [{ type: 'image', data: absolutizeForQZ(job.url) }];
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
                dispatchFallback(job);
                emit('job-completed', { job, fallback: true });
                safeCallback(job.onComplete, job);
                job._resolve && job._resolve({ jobId: job.id, success: true, fallback: true });
                return;
        }

        try {
            logPrintJob(job, printer, 'processing');
            await qz.print(cfg, payload);
            emit('job-completed', { job });
            logPrintJob(job, printer, 'completed');
            safeCallback(job.onComplete, job);
            job._resolve && job._resolve({ jobId: job.id, success: true });
        } catch (err) {
            // v1.5.0 stale-socket recovery. When the tray quits / crashes /
            // the PC sleeps mid-session, qz.websocket.isActive() can STILL
            // report true while the socket underneath is gone — qz.print then
            // throws "TypeError: e.websocket.connection.sendData is not a
            // function" (or a similar websocket TypeError) instead of a real
            // print error, and every following print failed the same way
            // until a page reload. Detect it, force a REAL reconnect, and
            // retry the exact same job once before falling back.
            const rawMsg   = String((err && err.message) || err || '');
            const staleSocket = err instanceof TypeError
                && /sendData|not a function|websocket|connection/i.test(rawMsg);

            if (staleSocket) {
                console.warn('[SmartPrint] Dead tray socket detected — reconnecting and retrying once…');
                try { qz.websocket.close(); } catch (_) {}
                state.qzReady   = false;
                state.printers  = [];
                state._defaultPrinter = null; // fresh connection may resolve a new default

                const reconnected = await connectQZ(1);
                if (reconnected) {
                    try {
                        logPrintJob(job, printer, 'processing');
                        await qz.print(cfg, payload);
                        emit('job-completed', { job });
                        logPrintJob(job, printer, 'completed');
                        safeCallback(job.onComplete, job);
                        job._resolve && job._resolve({ jobId: job.id, success: true });
                        return;
                    } catch (retryErr) {
                        err = retryErr; // fall through to the normal failure path
                    }
                }
            }

            console.error('[SmartPrint] Print error:', err);
            emit('job-failed', { job, error: err });
            safeCallback(job.onError, err, job);
            logPrintJob(job, printer, 'failed', err && err.message);
            // v1.5 contract: when the fallback engine produced output, the
            // promise RESOLVES with { success: false, fallback: true } instead
            // of rejecting — callers no longer need try/catch to avoid
            // "Uncaught (in promise)" noise when the tray fails mid-print.
            // dispatchFallback (not fallback): if the v1.5.4 print watchdog
            // already degraded this job, a late error here must NOT print it
            // a second time.
            const printed = dispatchFallback(job);
            if (printed) {
                notifyFallback(job);
                job._resolve && job._resolve({ jobId: job.id, success: false, fallback: true, error: err });
            } else {
                job._reject && job._reject(err);
            }
        }
    }

    // ============================
    // Browser fallback engines (v1.5)
    // ============================
    // A job must NEVER dead-end because QZ Tray is missing, disconnected or
    // failed mid-print. fallback(job) dispatches on the job's fallback mode:
    //
    //   'auto'      default — printable specs go to the hidden-iframe engine,
    //               raw/zpl specs return false (nothing a browser can print;
    //               the offline retry queue takes over)
    //   'iframe'    hidden 0x0 iframe + contentWindow.print() — no popup
    //               blocker friction, dialogs come from the browser itself
    //   'window'    window.open(url) + print() (pre-1.5 behavior)
    //   'newtab'    plain new tab, no print() call
    //   'download'  <a download> click (blob URL generated for base64 PDFs)
    //   'queue'/'offline'  park the job for the retry queue (pre-1.5 default)
    //   'none'/false      do nothing, caller rejects
    //   function    custom (job, reason) => void — full control
    //
    // Mode source order: job.fallback → window.QZ_CONFIG.fallbackMode → 'auto'.
    // Returns true when something printable was actually dispatched.
    function resolveFallbackMode(job) {
        if (job && job.fallback !== undefined && job.fallback !== null) return job.fallback;
        return qzCfg('fallbackMode');
    }

    // Build a printable spec from a legacy job shape (type/url/data/element)
    // so the fallback engines share one format with the v1.5 action specs.
    function jobSpecForFallback(job) {
        const spec = { type: job.type, url: job.url, data: job.data, element: job.element, filename: job.filename };
        // v1.5: carry hydrated base64 + image mime through to the fallback
        // engines so a mid-print QZ failure still prints the fetched bytes.
        if (job.base64) {
            spec.base64 = job.base64;
        } else if ((job.type === 'pdf' || job.type === 'image') && typeof job.data === 'string'
                   && job.data.length > 40 && /^[A-Za-z0-9+/]+={0,2}$/.test(job.data)) {
            spec.base64 = job.data;   // legacy jobs kept raw base64 in `data`
            spec.data = undefined;
        }
        spec.imageMime = job.imageMime;
        if (spec.type === 'html' && spec.element && !spec.data) {
            spec.data = captureElement(spec.element, job);
        }
        return spec;
    }

    function printableSpecs(job) {
        return (job._specs && job._specs.length ? job._specs : [jobSpecForFallback(job)])
            .filter(s => s && (s.type === 'pdf' || s.type === 'html' || s.type === 'image')
                && (s.url || s.data || s.base64 || s.element));
    }

    // v1.5.4: phones/tablets cannot print through a 0x0 hidden iframe —
    // iOS Safari ignores contentWindow.print() there entirely and Android
    // Chrome rarely renders a PDF into a hidden frame, so the old auto
    // fallback "succeeded" while NOTHING happened on those devices. The
    // mobile path opens the document in a new tab instead (the OS viewer
    // shows it; the user prints/shares from there).
    function isMobileDevice() {
        try {
            const nav = (typeof navigator !== 'undefined') ? navigator : null;
            const ua  = (nav && nav.userAgent) || '';
            const touch = (nav && nav.maxTouchPoints) || 0;
            return /Android|iPhone|iPad|iPod|Mobile|Silk|Kindle/i.test(ua)
                || (/Macintosh/.test(ua) && touch > 1);   // iPadOS 13+ reports a Mac UA
        } catch (e) { return false; }
    }

    function fallback(job) {
        let mode = resolveFallbackMode(job);
        emit('fallback-print', { job, mode });

        if (typeof mode === 'function') {
            try { mode(job); } catch (e) { console.error('[SmartPrint] fallback callback error', e); }
            return true;
        }

        const specs = printableSpecs(job);

        if (mode === 'auto' || mode === undefined || mode === null) {
            // Anything a browser can print goes to the iframe engine; raw
            // printer languages return false so the offline queue retains it.
            // v1.5.4: mobile devices go through the new-tab engine instead —
            // hidden-iframe window.print() is a silent no-op there. Pin it
            // per page with window.QZ_CONFIG.mobileFallbackMode.
            if (!specs.length) {
                mode = 'none';
            } else {
                const mobileMode = qzCfg('mobileFallbackMode');
                mode = isMobileDevice()
                    ? (mobileMode === 'iframe' || mobileMode === 'newtab' || mobileMode === 'download' ? mobileMode : 'newtab')
                    : 'iframe';
            }
        }

        switch (mode) {
            case 'iframe':
                // Browsers cannot show two print dialogs at once — serialize
                // with a small gap so a batch of receipts prints one by one.
                if (!specs.length) return false;
                specs.reduce((p, s) => p.then(() => new Promise(res =>
                    setTimeout(() => fallbackIframe(s, job, res), 200))), Promise.resolve());
                return true;
            case 'window':
                return specs.map(windowFallback).some(Boolean);
            case 'newtab': {
                let dispatched = false;
                specs.forEach(s => {
                    if (windowFallback(s)) { dispatched = true; return; }
                    // v1.5.4: the fallback usually runs AFTER async hops
                    // (hydration fetch, connect attempt) — the original user
                    // gesture is long gone and window.open can be popup-
                    // blocked. A programmatic <a download> click is not
                    // gesture-gated; the watchdog-protected hidden iframe is
                    // the last resort. Either way SOMETHING is dispatched —
                    // the old code returned true here even when the popup
                    // was silently blocked and nothing happened at all.
                    if (downloadSpec(s)) { dispatched = true; return; }
                    queueFallbackTask(resolve => fallbackIframe(s, job, resolve));
                    dispatched = true;
                });
                return dispatched;
            }
            case 'download':
                return specs.map(downloadSpec).some(Boolean);
            default:
                // 'queue' / 'offline' / 'none' / false / unknown — caller
                // (offlineBuffer or the action runner) decides what's next.
                return false;
        }
    }

    // v1.5.4: single-dispatch guard. Two paths can now reach for the browser
    // fallback for the SAME job — the print watchdog in processQueue (QZ
    // never settled) and a printQZ that finally wakes up late with an
    // error. Without the guard a stalled receipt would print TWICE: once
    // from the watchdog, once when the tray finally answers. First one wins.
    function dispatchFallback(job) {
        if (!job || job._fallbackDone) return false;
        job._fallbackDone = true;
        return fallback(job);
    }

    // Serialize ALL iframe fallbacks through one chain — two simultaneous
    // window.print() calls in hidden frames make Chrome print the wrong
    // document or drop the second dialog entirely.
    let _fallbackChain = Promise.resolve();

    function queueFallbackTask(fn) {
        const run = () => new Promise(resolve => {
            try { fn(resolve); } catch (e) { console.warn('[SmartPrint] fallback failed:', e); resolve(); }
        });
        _fallbackChain = _fallbackChain.then(run, run).catch(() => {});
        return _fallbackChain;
    }

    // Hidden-iframe print — the same technique lab apps use for direct
    // Windows printing, minus the popup blocker and the blank-tab debris.
    function printIframe(src, done) {
        const old = document.getElementById('sp-fallback-frame');
        if (old) old.remove();

        const iframe = document.createElement('iframe');
        iframe.id   = 'sp-fallback-frame';
        iframe.style.cssText = 'width:0;height:0;border:0;position:absolute;left:-9999px;';
        iframe.src  = src;

        // v1.5.4: the cleanup timer used to be armed ONLY inside onload —
        // when the frame never fires load (mobile browsers + blob: PDFs,
        // blocked content, stalled network) done() NEVER ran and the one
        // serialized fallback chain stalled FOREVER: every later print
        // silently queued and only a full page reload printed again. A load
        // watchdog armed BEFORE load guarantees done() always runs.
        let finished = false;
        let loaded   = false;
        const WATCHDOG = parseInt(qzCfg('fallbackLoadWatchdogMs'), 10) || 20000;

        const finish = () => {
            if (finished) return;
            finished = true;
            clearTimeout(loadWatchdog);
            setTimeout(() => { if (iframe.parentNode) iframe.remove(); done && done(); }, 400);
        };

        const loadWatchdog = setTimeout(() => {
            if (loaded) return;   // frame loaded fine — the post-onload timer owns cleanup
            console.warn('[SmartPrint] fallback iframe never fired load — releasing the fallback queue.');
            finish();
        }, WATCHDOG);

        iframe.onload = () => {
            loaded = true;
            try {
                const win = iframe.contentWindow;
                if (!win) { finish(); return; }
                win.onafterprint = finish;          // Chrome/Edge/Firefox
                win.focus();
                win.print();
                // Safari never fires onafterprint inside iframes — and any
                // browser can stall — so fall back to a generous cleanup
                // timer instead of leaking the frame forever.
                setTimeout(finish, 60000);
            } catch (e) {
                console.warn('[SmartPrint] iframe print failed:', e);
                finish();
            }
        };

        document.body.appendChild(iframe);
    }

    // Shared hidden-iframe document printer (html payloads + image wrappers).
    function printHtmlInIframe(html, done) {
        const old = document.getElementById('sp-fallback-frame');
        if (old) old.remove();

        const iframe = document.createElement('iframe');
        iframe.id   = 'sp-fallback-frame';
        iframe.style.cssText = 'width:0;height:0;border:0;position:absolute;left:-9999px;';
        document.body.appendChild(iframe);

        try {
            const doc = iframe.contentDocument;
            doc.open(); doc.write(html); doc.close();
            const win = iframe.contentWindow;
            let finished = false;
            const finish = () => {
                if (finished) return;
                finished = true;
                setTimeout(() => { if (iframe.parentNode) iframe.remove(); done(); }, 400);
            };
            win.onafterprint = finish;
            win.focus();
            win.print();
            setTimeout(finish, 60000);
        } catch (e) {
            console.warn('[SmartPrint] iframe HTML print failed:', e);
            iframe.remove();
            done();
        }
    }

    function fallbackIframe(spec, job, done) {
        done = done || (() => {});

        if (spec.type === 'pdf') {
            // Base64 PDFs: convert to a blob URL (sandbox-friendly, no
            // server round-trip), then print like any other PDF URL.
            if (!spec.url && spec.base64) {
                const blobUrl = base64ToBlobUrl(spec.base64);
                if (!blobUrl) { done(); return; }
                spec.url = blobUrl;
            }
            if (!spec.url) { done(); return; }
            printIframe(spec.url, done);
            return;
        }

        // v1.5: image specs (hydrated base64 or URL) print through the same
        // hidden iframe as an <img> document — the inline onload defers
        // print() until the image is fully decoded, avoiding blank prints.
        if (spec.type === 'image') {
            const src = spec.base64
                ? 'data:' + (spec.imageMime || 'image/png') + ';base64,' + spec.base64
                : spec.url;
            if (!src) { done(); return; }
            printHtmlInIframe(
                '<!DOCTYPE html><html><head><title>Print</title>'
                + '<style>@page{margin:0}html,body{margin:0;padding:0}img{max-width:100%}</style></head>'
                + '<body><img src="' + escapeHtml(src) + '" onload="window.focus();window.print()"></body></html>',
                done
            );
            return;
        }

        if (spec.type === 'html') {
            const html = spec.data || (spec.element ? captureElement(spec.element, job) : '');
            if (!html) { done(); return; }
            printHtmlInIframe(html, done);
            return;
        }

        done(); // raw/zpl/escpos: nothing a browser can print
    }

    function windowFallback(spec) {
        let url = spec.url;
        if (!url && spec.base64) url = base64ToBlobUrl(spec.base64, spec.imageMime);
        const w = url ? window.open(url, '_blank') : window.open('', '_blank');
        if (!w) return false; // popup blocked — caller can try other modes
        if (spec.type === 'html' && spec.data) {
            w.document.write(spec.data);
            w.document.close();
        }
        try { w.onload = () => { try { w.print(); } catch (_) {} }; } catch (e) {}
        return true;
    }

    function downloadSpec(spec) {
        let href = spec.url;
        if (!href && spec.base64) href = base64ToBlobUrl(spec.base64, spec.imageMime);
        if (!href) return false;
        const a = document.createElement('a');
        a.href = href;
        if (spec.filename) a.download = spec.filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        return true;
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

        // AUDIT C3: printer names arrive from the OS (and potentially from a
        // malicious network share) and were interpolated into innerHTML
        // unescaped — the exact sink BUG-01 fixed in the demo blade view but
        // left open here. Build every node through the DOM API so no HTML
        // parsing ever happens on OS-provided strings.
        const box = document.createElement('div');
        box.className = 'sp-box';
        box.style.cssText = 'background:#fff;padding:24px;border-radius:8px;min-width:280px;max-width:400px;';

        const title = document.createElement('h3');
        title.style.cssText = 'margin:0 0 16px;';
        title.textContent = 'Select Printer';
        box.appendChild(title);

        if (state.printers.length) {
            state.printers.forEach(p => {
                const btn = document.createElement('button');
                btn.style.cssText = 'display:block;width:100%;margin:4px 0;padding:8px;cursor:pointer;';
                btn.textContent = p;               // textContent — never HTML
                btn.dataset.printer = p;           // data attribute — never interpolated
                box.appendChild(btn);
            });
        } else {
            const empty = document.createElement('p');
            empty.style.color = '#888';
            empty.textContent = 'No printers found. Is QZ Tray running?';
            box.appendChild(empty);
        }

        const cancelBtn = document.createElement('button');
        cancelBtn.id = 'sp-modal-cancel';
        cancelBtn.style.cssText = 'margin-top:12px;padding:6px 12px;cursor:pointer;';
        cancelBtn.textContent = 'Cancel';
        box.appendChild(cancelBtn);

        modal.appendChild(box);
        document.body.appendChild(modal);

        const abandon = () => {
            modal.remove();
            if (jobToQueue && jobToQueue._reject) {
                // v1.5: cancelling printer selection must not dead-end the
                // caller either — 'auto' fallback prints via the browser;
                // only modes with no printable output still reject.
                const printed = dispatchFallback(jobToQueue);
                if (printed) {
                    notifyFallback(jobToQueue);
                    jobToQueue._resolve({ jobId: jobToQueue.id, success: false, fallback: true, cancelled: true });
                } else {
                    jobToQueue._reject(new Error('Print cancelled: no printer selected'));
                }
            }
        };

        box.querySelectorAll('[data-printer]').forEach(btn => {
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

        cancelBtn.onclick = abandon;

        // Close on backdrop click
        modal.addEventListener('click', e => { if (e.target === modal) abandon(); });
    }

    // ============================
    // DOM Binding — supports both data-qz-print and legacy data-smart-print
    // ============================
    let _clickBound = false;

    // Read the auto-print data attributes off one element and queue it.
    // Shared by the initial page scan and the (optional) MutationObserver so
    // both paths stay in sync.
    function enqueueAutoPrintJob(el) {
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
    }

    function scanAutoPrint(root) {
        root = root || document;
        root.querySelectorAll('[data-qz-auto-print], [data-auto-print="true"]').forEach(enqueueAutoPrintJob);
    }

    // AUDIT M7: auto-print elements were scanned exactly once at init, so
    // anything injected later (Turbo/Inertia navigation, modals, AJAX)
    // never fired. bind() now accepts a root element, and opt-in DOM
    // observation (window.QZ_CONFIG.observeDom = true) auto-queues new
    // auto-print nodes as they appear.
    function bind(root) {
        root = root || document;

        // The delegated click listener is registered once, document-wide.
        if (! _clickBound) {
            _clickBound = true;
            document.addEventListener('click', e => {
                // v1.5: named-action buttons —
                //   <button data-qz-action="printLabReceipt"
                //           data-qz-url="/receipt/5.pdf"
                //           data-qz-target="#receipt-box"
                //           data-qz-printer="receipt" data-qz-copies="2">Print</button>
                // resolves to the action registered via SmartPrint.define().
                // Input precedence: data-qz-target (selector / element) >
                // data-qz-url > data-qz-data > href (for <a> tags) > none
                // (action template may carry its own default input).
                const actionEl = e.target.closest('[data-qz-action]');
                if (actionEl) {
                    e.preventDefault();
                    const action  = actionEl.dataset.qzAction;
                    const target  = actionEl.dataset.qzTarget;
                    // v1.5: data-qz-urls="u1|u2|u3" — batch buttons without JS.
                    const multi = (actionEl.dataset.qzUrls || '')
                        .split('|').map(s => s.trim()).filter(Boolean);
                    const input   = target !== undefined && target !== null
                        ? target
                        : (multi.length ? multi
                            : (actionEl.dataset.qzUrl || actionEl.dataset.qzData
                                || (actionEl.tagName === 'A' && actionEl.getAttribute('href')
                                    ? actionEl.getAttribute('href') : undefined)));
                    const overrides = {
                        printer: actionEl.dataset.qzPrinter || undefined,
                        copies:  parseInt(actionEl.dataset.qzCopies || '0', 10) || undefined,
                        profile: actionEl.dataset.qzProfile || undefined,
                        type:    actionEl.dataset.qzType    || undefined,
                        fetch:   actionEl.dataset.qzFetch === 'true' ? true
                                 : (actionEl.dataset.qzFetch === 'false' ? false : undefined),
                    };
                    try {
                        run(action, input, overrides).catch(err =>
                            console.warn('[SmartPrint] action "' + action + '" failed:', err));
                    } catch (err) {
                        // unknown action name — surface loudly in the console
                        console.error(err);
                    }
                    return;
                }

                // v1.5: class binding — ANY button/link with class "smart-print"
                // prints, no JS wiring needed:
                //   <button class="smart-print" data-qz-url="/receipt/5.pdf">Print</button>
                //   <a class="smart-print" href="/receipt/5.pdf">Print receipt</a>
                //   <button class="smart-print" data-qz-urls="/a.pdf|/b.pdf">Batch</button>
                //   <button class="smart-print" data-qz-element="#worklist">Worklist</button>
                //   <button class="smart-print" data-qz-html="<b>Hi</b>">Hi</button>
                //   <button class="smart-print" data-qz-data="JVBERi0xLj...">Bytes</button>
                // Routing precedence:
                //   1. data-qz-action (handled above — named action wins).
                //   2. the element's OWN onclick — left ENTIRELY to the app
                //      ("function route"): printUrl/printHtml/printElement/…
                //      are window globals, so onclick="printUrl('/x.pdf')"
                //      just works. We still preventDefault so an <a> doesn't
                //      navigate after printing — and we never auto-print on
                //      top of it, so nothing double-prints.
                //   3. data-qz-urls → printUrls | data-qz-url → printUrl
                //   4. data-qz-html → printHtml | data-qz-element → printElement
                //   5. data-qz-data / data-qz-base64 → printUrl (auto-detects
                //      base64 / data: URI payloads)
                //   6. plain <a href> → printUrl(href)
                //   7. nothing to print → silent console hint, page unharmed.
                const smartEl = e.target.closest('.smart-print');
                if (smartEl) {
                    if (typeof smartEl.getAttribute('onclick') === 'string') {
                        e.preventDefault(); // the onclick function does the printing
                        return;
                    }

                    e.preventDefault(); // buttons in <form> must not submit

                    const multi = (smartEl.dataset.qzUrls || '')
                        .split('|').map(s => s.trim()).filter(Boolean);
                    const overrides = {
                        printer:  smartEl.dataset.qzPrinter || undefined,
                        copies:   parseInt(smartEl.dataset.qzCopies || '0', 10) || undefined,
                        profile:  smartEl.dataset.qzProfile || undefined,
                        type:     smartEl.dataset.qzType    || undefined,
                        fetch:    smartEl.dataset.qzFetch === 'true' ? true
                                  : (smartEl.dataset.qzFetch === 'false' ? false : undefined),
                        filename: smartEl.dataset.qzFilename || undefined,
                    };

                    const route = (action, input) => {
                        try {
                            run(action, input, overrides).catch(err =>
                                console.warn('[SmartPrint] .smart-print "' + action + '" failed:', err));
                        } catch (err) {
                            console.error(err);
                        }
                    };

                    if (multi.length) return route('printUrls', multi);
                    if (smartEl.dataset.qzUrl || smartEl.dataset.url)
                        return route('printUrl', smartEl.dataset.qzUrl || smartEl.dataset.url);
                    if (smartEl.dataset.qzHtml)
                        return route('printHtml', smartEl.dataset.qzHtml);
                    if (smartEl.dataset.qzElement || smartEl.dataset.qzTarget)
                        return route('printElement', smartEl.dataset.qzElement || smartEl.dataset.qzTarget);
                    if (smartEl.dataset.qzData || smartEl.dataset.qzBase64)
                        return route('printUrl', smartEl.dataset.qzData || smartEl.dataset.qzBase64);

                    const href = smartEl.tagName === 'A' ? smartEl.getAttribute('href') : null;
                    if (href && href !== '#' && !/^(javascript|mailto|tel):/i.test(href))
                        return route('printUrl', href);

                    console.info('[SmartPrint] .smart-print element has nothing to print — add '
                        + 'data-qz-url="/doc.pdf", data-qz-html, data-qz-element, '
                        + 'data-qz-urls="a.pdf|b.pdf", data-qz-data, or onclick="printUrl(...)".');

                    return;
                }

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
        }

        if (root === document) {
            scanAutoPrint(document);
        } else if (root.querySelectorAll) {
            root.querySelectorAll('[data-qz-auto-print], [data-auto-print="true"]').forEach(enqueueAutoPrintJob);
        }
    }

    function observeDom() {
        if (typeof MutationObserver === 'undefined' || !document.body) return;

        new MutationObserver(mutations => {
            mutations.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (node.nodeType !== 1) return;
                    if (node.matches && node.matches('[data-qz-auto-print], [data-auto-print="true"]')) {
                        enqueueAutoPrintJob(node);
                    }
                    if (node.querySelectorAll) {
                        node.querySelectorAll('[data-qz-auto-print], [data-auto-print="true"]')
                            .forEach(enqueueAutoPrintJob);
                    }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
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
            // AUDIT C3: innerHTML with an unescaped job.url was an XSS sink —
            // a crafted data-qz-print URL (or any job URL derived from user
            // input) executed script in the host page. textContent is inert.
            li.textContent = `Failed: ${job.type} — ${job.url || 'Raw Data'} `;
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
    // Hotkey — configurable via QZ_CONFIG.hotkey (AUDIT M5)
    // ============================
    // The hotkey.enabled / hotkey.combination settings existed in the PHP
    // config but were never sent to the browser — this listener hardcoded
    // ctrl+shift+p. It now honors window.QZ_CONFIG.hotkey (bridged by the
    // package views) and still defaults to ctrl+shift+p otherwise.
    (() => {
        const hotkeyCfg = qzCfg('hotkey') || {};
        if (hotkeyCfg.enabled === false) return;

        const combo = String(hotkeyCfg.combination || 'ctrl+shift+p')
            .toLowerCase().split('+').map(s => s.trim()).filter(Boolean);
        const modifiers = ['ctrl', 'shift', 'alt', 'meta', 'cmd'];
        const wantCtrl  = combo.includes('ctrl');
        const wantShift = combo.includes('shift');
        const wantAlt   = combo.includes('alt');
        const wantMeta  = combo.includes('meta') || combo.includes('cmd');
        const keyPart   = combo.filter(k => !modifiers.includes(k)).pop() || 'p';

        document.addEventListener('keydown', e => {
            if (e.ctrlKey !== wantCtrl || e.shiftKey !== wantShift
                || e.altKey !== wantAlt || e.metaKey !== wantMeta) return;
            if ((e.key || '').toLowerCase() !== keyPart) return;
            e.preventDefault();
            openPrinterModal(null);
        });
    })();

    // ============================
    // Hotkey — Ctrl+Shift+Q tray connection check (v1.5.5)
    // ============================
    // Press anywhere: ONE manual tray probe with a toast + console report
    // ("QZ Tray connected — 2 printers · using XP-80C" / "QZ Tray NOT
    // running — printing via the browser dialog"). It overrides any
    // cooldown, so it doubles as "I just started the tray — reconnect
    // NOW". Disable/override per page:
    //   window.QZ_CONFIG.connectionHotkey = { enabled: false }
    //   window.QZ_CONFIG.connectionHotkey = { combination: 'ctrl+alt+q' }
    (() => {
        const cfg = qzCfg('connectionHotkey') || {};
        if (cfg.enabled === false) return;

        const combo = String(cfg.combination || 'ctrl+shift+q')
            .toLowerCase().split('+').map(s => s.trim()).filter(Boolean);
        const modifiers = ['ctrl', 'shift', 'alt', 'meta', 'cmd'];
        const wantCtrl  = combo.includes('ctrl');
        const wantShift = combo.includes('shift');
        const wantAlt   = combo.includes('alt');
        const wantMeta  = combo.includes('meta') || combo.includes('cmd');
        const keyPart   = combo.filter(k => !modifiers.includes(k)).pop() || 'q';

        document.addEventListener('keydown', e => {
            if (e.ctrlKey !== wantCtrl || e.shiftKey !== wantShift
                || e.altKey !== wantAlt || e.metaKey !== wantMeta) return;
            if ((e.key || '').toLowerCase() !== keyPart) return;
            e.preventDefault();
            connectionCheck();
        });
    })();

    // ============================
    // Auto-reconnect if disconnected (with backoff) — OPT-IN since v1.5.5
    // ============================
    // v1.5.0: a machine without (or with a crashed) QZ Tray used to hammer
    // all four QZ ports every 10 seconds forever — a wall of
    // "WebSocket connection failed" console spam.
    //
    // v1.5.4: TWO changes kill the storm the console used to show:
    //   1. ticks pass retries=0 to connectQZ — ONE full port scan (8
    //      candidate sockets) per tick instead of two scans + a 1.5s nap;
    //   2. the tick interval backs off progressively per consecutive
    //      failure — 10s → 30s → 60s → 2min → 5min (cap). A success resets
    //      the ladder immediately, so a tray started mid-session is picked
    //      up within seconds of the NEXT tick.
    //
    // v1.5.5: background probing is OPT-IN. Lazy connect + the print-time
    // probe + the unavailable cooldown fully replace it — a page whose tray
    // is absent now performs ZERO background scans. Live status pages opt
    // back in with window.QZ_CONFIG.autoReconnect = true (the ladder below
    // still applies, and the cooldown still gates every tick).
    let reconnectFails = 0;
    const RECONNECT_LADDER = [10000, 30000, 60000, 120000, 300000];
    setInterval(() => {
        if (!window.qz || qz.websocket.isActive()) return;
        if (qzCfg('autoReconnect') !== true) return; // v1.5.5
        if (qzInCooldown()) return;                                                 // v1.5.5
        // state.connectPromise covers a HUNG attempt (phone handshake that
        // never settles) — without this guard every tick would still think
        // it is due and pile a second chain behind the stuck one.
        if (state.connecting || state.connectPromise) return;

        const wait = RECONNECT_LADDER[Math.min(reconnectFails, RECONNECT_LADDER.length - 1)];
        if (Date.now() - state._lastReconnectTry < wait) return;

        state._lastReconnectTry = Date.now();
        connectQZ(0).then(ok => {
            reconnectFails = ok ? 0 : reconnectFails + 1;
        }).catch(() => {
            // Swallow — the connection-failed event already fires inside.
            reconnectFails++;
        });
    }, 10000);

    // ============================
    // Init on DOMContentLoaded
    // ============================
    function init() {
        bind();
        if (qzCfg('observeDom')) {
            observeDom();
        }
        // v1.5.5: LAZY connection. A page load no longer scans the tray
        // ports, no longer fetches /qz/printer, and no longer produces a
        // single console error on machines without QZ Tray. The first
        // print — or Ctrl+Shift+Q — connects. Machines WITH the tray behave
        // exactly as before (the first print pays the connect; later prints
        // are instant). Opt back into connect-on-load:
        // window.QZ_CONFIG.connectOnInit = true.
        if (resolveConnectOnInit()) {
            connectQZ().then(() => { retryOffline(); updateQueueUI(); });
        }
        updateQueueUI();
        emit('ready', { printers: state.printers, lazyConnect: !resolveConnectOnInit() });
    }

    // v1.5.0: arm the certificate/sign resolvers IMMEDIATELY — not lazily on
    // the first connect. app.js bundles open the socket during their own
    // bundle evaluation (before DOMContentLoaded), and QZ Tray's very first
    // challenge then found no certificate promise at all:
    // qz-tray.js callCert() rejects bare -> "Failed to get certificate:
    // undefined" -> sendCert(null) -> unauthenticated session.
    try { setupSecurity(); } catch (e) { /* qz lib absent — connectQZ warns */ }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // ============================
    // Smart Actions (v1.5) — content resolvers & named actions
    // ============================
    // One call, any input, no dead ends:
    //
    //   SmartPrint.define({
    //     printLabReceipt:  { type: 'pdf', profile: 'a4' },
    //     printLabReceipts: { type: 'pdf', profile: 'a4' },
    //     printWorkList:    { type: 'pdf', profile: 'small', printer: 'receipt' },
    //   });
    //   printLabReceipt('/receipt/5.pdf');              // window global, auto-created
    //   printLabReceipts([url1, url2, url3]);           // sequential batch
    //   printWorkList('#worklist-table');               // any element by selector
    //   SmartPrint.run('printLabReceipt', base64OrUrl); // programmatic
    //
    // If QZ Tray is installed+connected → silent print. If not → the exact
    // same call degrades to the hidden-iframe browser print (or download /
    // new tab / custom function) — the page never crashes and the caller
    // never needs an if(qz) branch.

    function querySelectorSafe(selector) {
        try { return document.querySelector(selector); } catch (e) { return null; }
    }

    // data:/blob: base64 payload -> object URL (for iframe/window/download
    // fallbacks). Returns null when the payload cannot be decoded.
    function base64ToBlobUrl(b64, mime) {
        try {
            const clean = String(b64).replace(/^data:[^,]*;base64,/, '').replace(/\s+/g, '');
            const bin = atob(clean);
            const bytes = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            return URL.createObjectURL(new Blob([bytes], { type: mime || 'application/pdf' }));
        } catch (e) {
            return null;
        }
    }

    // ArrayBuffer -> base64 (chunked — apply() with the whole buffer blows
    // the call stack on multi-megabyte PDFs).
    function bufToBase64(buffer) {
        const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
        let bin = '';
        const CHUNK = 0x8000;
        for (let i = 0; i < bytes.length; i += CHUNK) {
            bin += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK));
        }
        return btoa(bin);
    }

    function isSameOriginUrl(u) {
        try { return new URL(u, location.href).origin === location.origin; }
        catch (e) { return false; }
    }

    // ============================================================
    // Browser-fetch engine (v1.5)
    // ============================================================
    // QZ Tray downloads URLs ITSELF when handed one — with no browser
    // session, no cookies, no Laravel auth. An auth-protected route
    // therefore returned login HTML to QZ ("Cannot parse … End-of-File").
    //
    // v1.5 fixes that on the client: same-origin URLs are fetched BY THE
    // PAGE first (session cookies flow automatically), the Content-Type
    // picks the print path, and the bytes go to QZ as base64:
    //
    //   application/pdf   -> QZ pdf payload   (mPDF ->stream() / ->download())
    //   image/*           -> QZ image payload
    //   text/html         -> QZ html payload  (auth-protected print views!)
    //
    // Modes: 'auto' (default) same-origin only | 'always' | 'never' / false.
    // Per call: printUrl(url, { fetch: true | false }). Misdirected
    // responses (a login/404 page where a document was expected) are
    // detected and NOT printed — the result explains why instead.
    async function hydrateSpec(spec, forcedType, callOpts) {
        if (!spec || !spec.url || spec.base64 || spec.data) return spec;
        if (spec.type !== 'pdf' && spec.type !== 'image' && spec.type !== 'html') return spec;

        const mode = (callOpts && callOpts.fetch !== undefined) ? callOpts.fetch
            : spec.fetch !== undefined ? spec.fetch
            : qzCfg('fetchMode');
        if (mode === false || mode === 'never') return spec;
        if (mode !== 'always' && !isSameOriginUrl(spec.url)) return spec;

        try {
            const res = await fetch(spec.url, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/pdf, image/*, text/html, */*' },
            });

            spec.hydrate = { ok: res.ok, status: res.status };
            // Non-OK (401/403/404/419/500 …): keep the URL — the QZ/fallback
            // chain still manages it, and QZ Tray may even succeed where the
            // page's fetch was refused. Misdirected CONTENT (a 200 OK that
            // answers text/html where a pdf/image was expected) is refused
            // further down.
            if (!res.ok) {
                // v1.5.4: make the degrade VISIBLE — a silent !ok here sends
                // QZ a (FILE) it must download WITHOUT the browser session,
                // which fails confusingly ("PDF file specified could not be
                // found" / "Cannot parse"). Surface the real status.
                console.warn('[SmartPrint] hydration skipped: ' + spec.url
                    + ' answered HTTP ' + res.status
                    + ' — QZ Tray will download the URL itself (no session).');
                return spec;
            }

            const ct = String((res.headers && res.headers.get && res.headers.get('Content-Type')) || '')
                .split(';')[0].trim().toLowerCase();
            spec.hydrate.contentType = ct;

            if (ct.indexOf('pdf') !== -1) {
                spec.base64 = bufToBase64(await res.arrayBuffer());
                spec.type = 'pdf';          // correct outcome beats a forced html guess
            } else if (ct.indexOf('image/') === 0) {
                spec.base64 = bufToBase64(await res.arrayBuffer());
                spec.imageMime = ct;
                if (forcedType !== 'pdf') spec.type = 'image';
            } else if (ct.indexOf('text/') === 0 || ct.indexOf('xml') !== -1) {
                const text = await res.text();
                // A PDF/image URL that answers with text is a login/404/error
                // page — never print that.
                if (forcedType === 'pdf' || forcedType === 'image' || looksLikeDocumentUrl(spec.url)) {
                    spec.hydrate.misdirected = true;
                    return spec;
                }
                spec.data = text;
                spec.type = 'html';
            } else {
                // octet-stream / JSON / unlabeled — sniff the leading bytes.
                const buf = new Uint8Array(await res.arrayBuffer());
                const is = (...sig) => sig.every((b, i) => buf[i] === b);
                if (is(0x25, 0x50, 0x44, 0x46)) {                      // %PDF-
                    spec.base64 = bufToBase64(buf); spec.type = 'pdf';
                } else if (is(0x89, 0x50, 0x4E, 0x47)) {               // PNG
                    spec.base64 = bufToBase64(buf); spec.imageMime = 'image/png'; spec.type = 'image';
                } else if (is(0xFF, 0xD8)) {                           // JPEG
                    spec.base64 = bufToBase64(buf); spec.imageMime = 'image/jpeg'; spec.type = 'image';
                } else if (is(0x47, 0x49, 0x46)) {                     // GIF
                    spec.base64 = bufToBase64(buf); spec.imageMime = 'image/gif'; spec.type = 'image';
                } else {
                    spec.hydrate.misdirected = true;                   // JSON/API error etc.
                }
            }

            // Hydrated: QZ receives the BYTES; the original URL moves to
            // sourceUrl so the browser fallback engines can still reach it.
            if (spec.base64 || spec.data) {
                spec.sourceUrl = spec.url;
                spec.url = undefined;
            }
        } catch (e) {
            spec.hydrate = { ok: false, error: String((e && e.message) || e) };
        }
        return spec;
    }

    // Snapshot a live DOM element into a standalone printable document.
    // Stylesheets + <style> blocks are cloned so the printout matches the
    // screen (toggle off with styles: false for raw-speed printing).
    function captureElement(el, options) {
        if (!el || !el.outerHTML) return '';
        const styles = !(options && options.styles === false);
        const html = (options && options.inner === true) ? el.innerHTML : el.outerHTML;
        let head = '<title>Print</title>';
        if (styles) {
            try {
                const links  = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'))
                    .map(l => l.outerHTML).join('\n');
                const styles_ = Array.from(document.querySelectorAll('style'))
                    .map(s => s.outerHTML).join('\n');
                head += '<base href="' + location.origin + location.pathname + '">'
                     + links + '\n' + styles_;
            } catch (e) { /* headless/edge-case DOMs — print unstyled */ }
        }
        return '<!DOCTYPE html><html><head>' + head + '</head>'
             + '<body style="margin:0;padding:0">' + html + '</body></html>';
    }

    // Guess pdf / image / html for a URL when the caller did not say.
    // Extension-less Laravel routes (the common case) default to pdf.
    function sniffType(url) {
        const s = String(url);
        if (/\.(png|jpe?g|gif|webp|svg|bmp)([?#]|$)/i.test(s)) return 'image';
        if (/\.pdf([?#]|$)/i.test(s)) return 'pdf';
        return /\.(html?|php|aspx?)([?#]|$)|\/print\b/i.test(s) ? 'html' : 'pdf';
    }

    // Looks like a direct document link (.pdf / image extension)? Used to
    // decide whether a text response is a misdirected login/error page.
    function looksLikeDocumentUrl(url) {
        return /\.(pdf|png|jpe?g|gif|webp|svg|bmp)([?#]|$)/i.test(String(url));
    }

    // v1.5.0: detect a base64 PDF accidentally used as (part of) a URL —
    // the "pass mPDF's base64 output as the route parameter" bug seen in
    // receipt blades: printUrl('/lab/receipt/edit/JVBERi0xLjQ…'). Those URLs
    // are hundreds of KB, die with HTTP 414 (URI Too Long) at the server, and
    // QZ Tray can never download them. The bytes are already IN the page, so
    // they can be printed directly instead of fetched. 'JVBERi' is base64 for
    // '%PDF-'. Tolerates %-encoded +/= so URI-encoded paths match as well.
    function embeddedPdfInUrl(s) {
        if (typeof s !== 'string' || s.length < 600) return null;
        // v1.5.4: decode EVERY %xx escape and strip whitespace/newlines before
        // matching — inputs pasted from error logs / MIME-wrapped sources are
        // frequently URL-encoded or line-wrapped, which broke the old probe.
        const probe = String(s)
            .replace(/%([0-9A-Fa-f]{2})/g, (m, h) => String.fromCharCode(parseInt(h, 16)))
            .replace(/\s+/g, '');
        const m = probe.match(/(?:^|\/|[:=])(JVBERi[A-Za-z0-9+\/=]{500,})/);
        return m ? m[1] : null;
    }

    // v1.5.4: QZ Tray has NO page context — a RELATIVE (FILE) reference fails
    // with "PDF file specified could not be found." Resolve every URL handed
    // to QZ against the page origin (blob:/data:/absolute URLs pass through).
    function absolutizeForQZ(u) {
        if (typeof u !== 'string' || !u || /^(https?:|blob:|data:|file:)/i.test(u)) return u;
        try { return new URL(u, (window.location && window.location.href) || '/').href; }
        catch (e) { return u; }
    }

    // Reduce ONE input (already resolved from functions/arrays) to a spec:
    // { type, url?, data?, base64?, element?, filename?, ...per-input opts }.
    // Accepted shapes: url string | css selector | HTMLElement |
    // {url|html|pdf|base64|element|el|selector|zpl|escpos|raw|type+data} |
    // data:application/pdf;base64,... strings.
    function resolveOne(input) {
        if (input === null || input === undefined) return null;

        if (typeof input === 'string') {
            const s = input.trim();
            if (!s) return null;

            if (/^data:[^,]*;base64,/i.test(s)) {
                const raw = s.replace(/^[^,]*,/, '');
                if (/^data:application\/pdf/i.test(s)) return { type: 'pdf', base64: raw };
                const m = s.match(/^data:(image\/[a-z0-9.+-]+);base64,/i);
                if (m) return { type: 'image', base64: raw, imageMime: m[1].toLowerCase() };
                // any other data: URI (svg text, unknown binary…) -> <img> wrapper
                return { type: 'html', data: '<img src="' + s + '" style="max-width:100%">' };
            }
            // v1.5.0: rescue a PDF payload that was glued INTO a URL
            // (e.g. '/lab/receipt/edit/JVBERi0xLjQ…') — print the bytes
            // directly instead of fetching the oversized URL.
            const embedded = embeddedPdfInUrl(s);
            if (embedded) {
                console.warn('[SmartPrint] URL carried an embedded PDF payload ('
                    + Math.round(embedded.length / 1024) + ' KB base64) — printing the bytes directly.');
                return { type: 'pdf', base64: embedded };
            }
            // Long base64-looking payload (no scheme, no selector chars)
            if (s.length >= 32 && /^[A-Za-z0-9+/]+={0,2}$/.test(s)) {
                if (/^JVBER/i.test(s)) return { type: 'pdf', base64: s };           // "%PDF-"
                if (/^(iVBOR|\/9j\/|R0lGOD|UklGR)/i.test(s)) return { type: 'image', base64: s };
                return { type: 'pdf', base64: s };                                  // PDFs dominate
            }
            // Raw HTML fragment (printHtml('<h1>…</h1>')) — needs a leading tag
            if (/^\s*<(?:!doctype|html|head|body|div|span|table|thead|tbody|tr|td|th|h[1-6]|p|ul|ol|li|img|section|article|main|form|style|svg|a)\b/i.test(s)) {
                return { type: 'html', data: s };
            }
            if (/^(https?:)?\/\//i.test(s) || /^blob:/i.test(s) || s.charAt(0) === '/') {
                return { type: sniffType(s), url: s };
            }
            // Not a URL shape — try it as a CSS selector (#id, .class, any selector)
            const el = querySelectorSafe(s);
            if (el) return { type: 'html', element: el };
            // Last resort: treat as a relative URL
            return { type: sniffType(s), url: s };
        }

        if (typeof input === 'object') {
            // A live DOM node (or jQuery object, courtesy of instanceof-safe duck typing)
            if (typeof HTMLElement !== 'undefined' && input instanceof HTMLElement) {
                return { type: 'html', element: input };
            }
            if (typeof input.length === 'number' && typeof input.jquery !== 'undefined') {
                return input.length ? { type: 'html', element: input[0] } : null;
            }

            const spec = {};
            if (input.type) spec.type = String(input.type).toLowerCase();
            if (input.url !== undefined) {
                spec.url = input.url;
                if (!spec.type) spec.type = sniffType(input.url);
                // v1.5.0: same embedded-PDF rescue for the object form
                // { url: '/lab/receipt/edit/JVBERi…' }
                if (!spec.data && !spec.base64 && !spec.element) {
                    const embedded = embeddedPdfInUrl(spec.url);
                    if (embedded) {
                        console.warn('[SmartPrint] URL carried an embedded PDF payload ('
                            + Math.round(embedded.length / 1024) + ' KB base64) — printing the bytes directly.');
                        spec.base64 = embedded;
                        spec.type   = 'pdf';
                        spec.url    = undefined;
                    }
                }
            }
            if (input.html !== undefined) { spec.type = 'html'; spec.data = input.html; }
            if (input.pdf !== undefined) {
                spec.type = 'pdf';
                if (typeof input.pdf === 'string' && !/^(https?:)?\/\/|^\/|^blob:/i.test(input.pdf)) {
                    spec.base64 = input.pdf;          // raw base64 payload
                } else {
                    spec.url = input.pdf;             // URL
                }
            }
            if (input.base64 !== undefined) {
                if (!spec.type) spec.type = (input.mime && /image|text/.test(input.mime)) ? 'html' : 'pdf';
                if (spec.type === 'html') spec.data = input.base64;
                else spec.base64 = input.base64;
            }
            if (input.element || input.el) { spec.type = spec.type || 'html'; spec.element = input.element || input.el; }
            if (input.selector) {
                const el = querySelectorSafe(input.selector);
                if (el) { spec.type = spec.type || 'html'; spec.element = el; }
            }
            if (input.zpl !== undefined)   { spec.type = 'zpl';    spec.data = input.zpl; }
            if (input.escpos !== undefined){ spec.type = 'escpos'; spec.data = input.escpos; }
            if (input.raw  !== undefined)  { spec.type = input.rawType || 'raw'; spec.data = input.raw; }
            if (input.data !== undefined && !spec.data && !spec.base64) {
                spec.data = input.data;
                if (!spec.type) spec.type = input.format === 'base64' ? 'pdf' : (input.type === 'html' ? 'html' : 'raw');
            }

            // Per-input overrides flow through to specToJob
            ['printer', 'copies', 'profile', 'fallback', 'filename', 'styles', 'mime', 'rawType', 'imageMime', 'fetch'].forEach(k => {
                if (input[k] !== undefined) spec[k] = input[k];
            });

            if (spec.type || spec.url || spec.data || spec.base64 || spec.element) return spec;
        }

        return null;
    }

    // Flatten any input into a list of specs. Functions are invoked lazily
    // (so a button can resolve its URL at click time), arrays fan out into
    // sequential batch printing.
    function resolveInputs(input) {
        if (input === undefined || input === null) return [];
        if (typeof input === 'function') return resolveInputs(input());
        if (Array.isArray(input)) {
            const out = [];
            input.forEach(item => out.push(...resolveInputs(item)));
            return out;
        }
        const one = resolveOne(input);
        return one ? [one] : [];
    }

    // Merge one spec + the action template + per-call overrides into a job
    // the existing queue/printQZ/fallback pipeline already understands.
    function specToJob(spec, tpl, overrides) {
        const pick = (key, fallbackValue) =>
            overrides[key] !== undefined ? overrides[key]
            : spec[key]   !== undefined ? spec[key]
            : tpl[key]    !== undefined ? tpl[key]
            : fallbackValue;

        const type = String(pick('type', null) || spec.type || tpl.type || 'pdf').toLowerCase();
        const job = {
            type,
            url:     spec.url || undefined,
            data:    spec.data || undefined,
            base64:  spec.base64 || undefined,
            imageMime: pick('imageMime', undefined),
            element: spec.element || undefined,
            printer: resolveAlias(pick('printer', null)) || undefined,
            copies:  parseInt(pick('copies', 1), 10) || 1,
            profile: pick('profile', undefined),
            fallback: pick('fallback', undefined),
            filename: pick('filename', undefined),
            styles:   pick('styles', undefined),
            onComplete: overrides.onComplete || tpl.onComplete,
            onError:    overrides.onError    || tpl.onError,
        };

        // PDF / image via base64: feed QZ its native payload shape
        if (type === 'pdf' && !job.url && job.base64) job.data = job.base64;
        if (type === 'image' && !job.url && job.base64) job.data = job.base64;

        // HTML captured from a live element: snapshot NOW, before the queue
        // reaches the job (the element may re-render between click and print)
        if (type === 'html' && job.element && !job.data) {
            job.data = captureElement(job.element, job);
        }

        return job;
    }

    // ---- action registry -------------------------------------------------
    const actions = {};
    const printerAliases = {};

    function resolveAlias(printer) {
        return (printer && printerAliases[printer]) ? printerAliases[printer] : printer;
    }

    // Map a friendly alias onto a real OS printer name. Aliases resolve at
    // PRINT time (not queue time), so a mapping added later still applies to
    // already-queued jobs, and re-mapping before a big batch just works:
    //   SmartPrint.aliasPrinter('receipt', 'XP-80C');
    //   SmartPrint.aliasPrinter('label',   'Zebra GK420d');
    function aliasPrinter(alias, realPrinter) {
        if (typeof alias !== 'string' || !alias) return apiRef;
        if (realPrinter === null || realPrinter === undefined) {
            delete printerAliases[alias]; // unmap
        } else {
            printerAliases[alias] = String(realPrinter);
        }
        return apiRef;
    }

    // Forward declaration — assigned just before the Proxy below so
    // define()/window-globals can reference the public API.
    let apiRef;

    async function run(name, input, overrides) {
        const tpl = actions[name];
        if (!tpl) {
            throw new Error('[SmartPrint] unknown action "' + name + '" — register it first: '
                + "SmartPrint.define('" + name + "', { ... })");
        }

        const opts = overrides || {};

        // v1.5: printElement(s) — an unmatched selector must error clearly,
        // not silently degrade into a relative-URL guess.
        if (tpl.elementOnly && typeof input === 'string') {
            const el = querySelectorSafe(input);
            if (!el || (typeof HTMLElement !== 'undefined' && !(el instanceof HTMLElement))) {
                const err = new Error('[SmartPrint] ' + name + ': no element found for "' + input + '"');
                safeCallback(opts.onError || tpl.onError, err, null);
                throw err;
            }
            input = el;
        }

        // v1.5: printPage — snapshot the whole page, no input required.
        if (tpl.page) {
            if (opts.mode === 'browser') {
                try { window.print(); } catch (e) { console.warn('[SmartPrint] window.print failed:', e); }
                return { success: true, fallback: true, via: 'window.print' };
            }
            const pageOpts = Object.assign({}, opts, { inner: true });
            return enqueue(specToJob(
                { type: 'html', data: captureElement(document.body, pageOpts) },
                { type: 'html' }, opts));
        }

        const specs = resolveInputs(input !== undefined ? input : tpl.input);

        if (!specs.length) {
            const err = new Error('[SmartPrint] action "' + name + '" has nothing to print '
                + '(pass a URL, base64, selector or element)');
            safeCallback(opts.onError || tpl.onError, err, null);
            throw err;
        }

        // v1.5: browser-fetch hydration — same-origin URLs are downloaded by
        // the PAGE (session cookies included) before reaching QZ Tray, which
        // itself has no session. See hydrateSpec().
        const forcedType = tpl.type || undefined;
        const results = [];
        for (let spec of specs) {
            spec = await hydrateSpec(spec, forcedType, opts);
            if (spec.hydrate && spec.hydrate.misdirected) {
                console.warn('[SmartPrint] ' + name + ': ' + spec.url + ' returned '
                    + (spec.hydrate.contentType || 'unknown')
                    + ' (HTTP ' + (spec.hydrate.status || '?') + ') — login/error page? Nothing printed.');
                results.push({
                    success: false,
                    reason: 'unexpected-response',
                    url: spec.url,
                    contentType: spec.hydrate.contentType || undefined,
                    status: spec.hydrate.status || undefined,
                });
                continue;
            }
            results.push(await enqueue(specToJob(spec, tpl, opts)));
        }
        return results.length === 1 ? results[0] : results;
    }

    // Register (or replace) a named action. Also installs a window global
    // (printLabReceipt(...) etc.) when the name is free — guarded, so an
    // existing app function is NEVER silently overwritten.
    function define(name, template) {
        if (name && typeof name === 'object' && !Array.isArray(name)) {
            Object.keys(name).forEach(key => define(key, name[key]));
            return apiRef;
        }
        if (typeof name !== 'string' || !name) return apiRef;

        actions[name] = (template === true || template === undefined) ? {} : (template || {});

        try {
            if (window[name] === undefined) {
                window[name] = (input, overrides) => run(name, input, overrides);
            } else if (window[name] !== apiRef && typeof console !== 'undefined') {
                console.warn('[SmartPrint] define("' + name + '"): window.' + name
                    + ' already exists and was left untouched — call SmartPrint.run("' + name
                    + '", ...) directly, or remove the old function.');
            }
        } catch (e) { /* sandboxed window — global helpers unavailable */ }

        return apiRef;
    }

    function status() {
        return {
            qzLibrary:      !!window.qz,
            connected:      !!(window.qz && qz.websocket.isActive()),
            printers:       [...state.printers],
            currentPrinter: state.currentPrinter,
            queued:         state.queue.length,
            failed:         state.failedQueue.length,
            fallbackMode:   resolveFallbackMode({}),
            actions:        Object.keys(actions),
            // v1.5.5: how much longer prints skip the tray (failed-scan
            // cooldown). 0 = the next print will probe the tray normally.
            unavailableForMs: Math.max(0, (state.qzUnavailableUntil || 0) - Date.now()),
            connectOnInit:    resolveConnectOnInit(),
        };
    }

    // v1.5.5 — the Ctrl+Shift+Q connection check (and SmartPrint.connectionCheck()).
    // ONE manual probe: clears the unavailable cooldown (explicit user
    // intent — "I just started the tray, reconnect NOW"), scans the tray
    // ports once, and reports the result as a toast + console line. Never
    // throws, never prints — pure diagnostics + recovery.
    async function connectionCheck() {
        if (!window.qz) {
            toastNotice('QZ Tray: library not loaded — browser printing only');
            console.info('[SmartPrint] connection check: qz-tray.min.js is not on this page — the browser fallback handles every print.');
            return { ok: false, reason: 'qz-library-missing' };
        }
        state.qzUnavailableUntil = 0;   // a manual check always overrides the cooldown
        let ok = false;
        try { ok = await connectQZ(0) === true; } catch (e) { ok = false; }
        if (ok || (qz.websocket && qz.websocket.isActive())) {
            const n = state.printers.length;
            toastNotice('QZ Tray connected — ' + n + ' printer' + (n === 1 ? '' : 's')
                + (state.currentPrinter ? ' · using ' + state.currentPrinter : ''));
            console.info('[SmartPrint] connection check: connected.',
                { printers: [...state.printers], using: state.currentPrinter });
            return { ok: true, reason: 'connected', printers: [...state.printers], printer: state.currentPrinter };
        }
        // connectQZ's failed scan already re-armed the cooldown.
        toastNotice('QZ Tray NOT running — printing via the browser dialog');
        console.info('[SmartPrint] connection check: tray not reachable. Prints use the browser fallback; '
            + 'the next print probes the tray again in ~' + Math.round(unavailableCooldownMs() / 1000) + 's.');
        return { ok: false, reason: 'connection-refused' };
    }

    // Resolves as soon as the tray connection is known — either established,
    // or definitively NOT available (missing library / refused). Never hangs
    // longer than timeoutMs, so callers can branch without waiting forever.
    function whenReady(timeoutMs) {
        timeoutMs = parseInt(timeoutMs, 10) || 5000;
        if (window.qz && qz.websocket.isActive()) {
            return Promise.resolve({ ok: true, reason: 'connected', printers: [...state.printers] });
        }
        return new Promise(resolve => {
            let settled = false;
            const finish = result => {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                resolve(result);
            };
            const onConnected = () => finish({ ok: true, reason: 'connected', printers: [...state.printers] });
            const timer = setTimeout(() => {
                off('connected', onConnected);
                finish({ ok: false, reason: window.qz ? 'timeout' : 'qz-library-missing' });
            }, timeoutMs);
            on('connected', onConnected);
            if (window.qz) {
                connectQZ(1).then(ok => { if (!ok) finish({ ok: false, reason: 'connection-refused' }); });
            } else {
                finish({ ok: false, reason: 'qz-library-missing' });
            }
        });
    }

    const publicApi = {
        // Core
        init,
        bind,
        print: (urlOrOptions, options) => {
            const opts = options || {};
            // v1.5: print(url) now rides the SAME pipeline as printUrl(url) —
            // type auto-detect + browser-fetch hydration + misdirected guard.
            if (typeof urlOrOptions === 'string') {
                return run('printUrl', urlOrOptions, opts);
            }

            // Legacy object form { url, type:'pdf', printer, copies, profile… }.
            const raw = { ...(urlOrOptions || {}) };
            if (raw.copies !== undefined) raw.copies = parseInt(raw.copies, 10) || 1;

            // v1.5: URL-only pdf/image/html jobs are hydrated through the
            // page (session cookies included) BEFORE QZ Tray downloads them
            // itself — previously print({url}) bypassed that, so QZ fetched
            // session-protected routes bare and failed with "Cannot parse
            // (FILE)… as a PDF". Raw/zpl/escpos and data-carrying jobs keep
            // the untouched legacy path.
            const spec0 = resolveOne(raw);
            const isUrlDoc = !!spec0 && !!spec0.url && !spec0.data && !spec0.base64 && !spec0.element
                && (spec0.type === 'pdf' || spec0.type === 'image' || spec0.type === 'html');
            if (!isUrlDoc) {
                // v1.5.0: if resolution CHANGED the input shape (embedded-PDF
                // in-URL rescue), print the resolved spec — the raw object
                // still carries the giant URL QZ would choke on (HTTP 414).
                if (spec0 && spec0.base64 && !raw.base64 && !raw.data) {
                    const job = specToJob(spec0, {}, opts);
                    if (raw.onComplete) job.onComplete = raw.onComplete;
                    if (raw.onError)    job.onError    = raw.onError;
                    return enqueue(job);
                }
                return enqueue(raw);
            }

            return hydrateSpec(spec0, spec0.type, opts).then(spec => {
                if (spec.hydrate && spec.hydrate.misdirected) {
                    console.warn('[SmartPrint] print(): ' + spec.url + ' returned '
                        + (spec.hydrate.contentType || 'unknown')
                        + ' (HTTP ' + (spec.hydrate.status || '?') + ') — login/error page? Nothing printed.');
                    emit('job-failed', {
                        job: spec,
                        error: new Error('unexpected-response'
                            + (spec.hydrate.contentType ? ' (' + spec.hydrate.contentType + ')' : '')),
                    });
                    return {
                        success: false,
                        reason: 'unexpected-response',
                        url: spec.url,
                        contentType: spec.hydrate.contentType || undefined,
                        status: spec.hydrate.status || undefined,
                    };
                }
                const job = specToJob(spec, {}, opts);
                // resolveOne() does not carry callbacks — restore the ones
                // the caller passed on the legacy object form.
                if (raw.onComplete) job.onComplete = raw.onComplete;
                if (raw.onError)    job.onError    = raw.onError;
                return enqueue(job);
            });
        },
        printRaw: (data, type, printer) => enqueue({ data, type: type || 'raw', printer, copies: 1 }),
        printZPL: (zpl, printer)   => enqueue({ data: zpl,   type: 'zpl',    printer, copies: 1 }),
        printESC: (escpos, printer) => enqueue({ data: escpos, type: 'escpos', printer, copies: 1 }),

        // ---- Smart Actions (v1.5) -------------------------------------
        version: '1.5.5',
        define,                       // register named actions (+ window globals)
        run,                          // run('printLabReceipt', input, overrides)
        has:    name => !!actions[name],
        actions: () => Object.keys(actions),
        aliasPrinter,                 // aliasPrinter('receipt', 'XP-80C')
        whenReady,                    // promise: is the tray usable or not?
        status,                       // full health snapshot

        // ---- Universal Print API (v1.5) --------------------------------
        // Routed through run() so every call gets the full pipeline: input
        // resolvers, browser-fetch hydration, per-call overrides, fallback.
        printUrl:      (input, options) => run('printUrl',      input, options),
        printUrls:     (input, options) => run('printUrls',     input, options),
        printAnyUrl:   (input, options) => run('printUrl',      input, options),
        printAnyUrls:  (input, options) => run('printUrls',     input, options),
        printPdf:      (input, options) => run('printPdf',      input, options),
        printPdfs:     (input, options) => run('printPdfs',     input, options),
        printImage:    (input, options) => run('printImage',    input, options),
        printImages:   (input, options) => run('printImages',   input, options),
        printHtml:     (input, options) => run('printHtml',     input, options),
        printHTML:     (input, options) => run('printHtml',     input, options), // v1.5 alias
        printElement:  (input, options) => run('printElement',  input, options),
        printElements: (input, options) => run('printElements', input, options),
        printPage:     (options)        => run('printPage',     undefined, options),

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
        getStatus:   () => status(),   // v1.5.0: alias — users instinctively type getStatus()
        connectionCheck,               // v1.5.5: Ctrl+Shift+Q tray probe (manual API too)
        checkConnection: connectionCheck,

        // Queue
        getQueue:    () => [...state.queue],
        clearQueue:  () => { state.queue = []; updateQueueUI(); emit('queue-cleared'); },

        // Settings
        getSettings:    () => ({ defaultPrinter: state.currentPrinter }),
        updateSettings: (s) => { if (s.defaultPrinter) rememberPrinter(s.defaultPrinter); emit('settings-updated', s); },
        // v1.5.5: the EFFECTIVE config — QZ_DEFAULTS merged with whatever the
        // page set on window.QZ_CONFIG. One call shows every knob and the
        // value actually in force (SmartPrint.config().autoReconnect etc.).
        config: () => Object.keys(QZ_DEFAULTS).reduce((out, key) => { out[key] = qzCfg(key); return out; }, {}),

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

    apiRef = publicApi;

    // ============================
    // Built-in universal actions (v1.5)
    // ============================
    // The generic names work out of the box — no define() needed. define()
    // registers the action AND creates a guarded window global, so plain
    // calls like printUrl('/receipt/5.pdf') or printPdfs([...]) just work,
    // as does <button data-qz-action="printUrl" data-qz-url="...">.
    // A later SmartPrint.define() with the same name (e.g. the app defining
    // printLabReceipt with a preset) cleanly overrides these templates.
    const BUILTIN_ACTIONS = {
        printUrl:      {},                                // type auto-detected
        printUrls:     {},                                // array = sequential batch
        printAnyUrl:   {},                                // readability alias
        printAnyUrls:  {},                                // readability alias
        printPdf:      { type: 'pdf' },
        printPdfs:     { type: 'pdf' },
        printImage:    { type: 'image' },
        printImages:   { type: 'image' },
        printHtml:     { type: 'html' },
        printElement:  { type: 'html', elementOnly: true },
        printElements: { type: 'html', elementOnly: true },
        printPage:     { page: true },
        // v1.5 business names kept working — now plain aliases:
        printLabReceipt:  {},
        printLabReceipts: {},
    };
    Object.keys(BUILTIN_ACTIONS).forEach(n => define(n, BUILTIN_ACTIONS[n]));

    // v1.5: Proxy magic — any registered action becomes a first-class method:
    //   SmartPrint.printLabReceipt('/receipt/5.pdf')
    //   SmartPrint.printLabReceipts([url1, url2])
    // Works even before you know the action names at authoring time.
    // (Proxy exists in every supported browser; older WebViews simply use
    // SmartPrint.run(name, ...) and the window globals from define().)
    if (typeof Proxy !== 'undefined') {
        return new Proxy(publicApi, {
            get(target, prop) {
                if (prop in target) return target[prop];
                if (typeof prop === 'string' && actions[prop]) {
                    return (input, overrides) => run(prop, input, overrides);
                }
                return undefined;
            },
            has(target, prop) {
                return prop in target || (typeof prop === 'string' && !!actions[prop]);
            },
        });
    }

    return publicApi;
})();

// ============================
// Global shorthand helpers
// ============================
// Guarded so including other libraries that happen to define the same names
// doesn't crash the page.
if (typeof window.smartPrint === 'undefined') {
    window.smartPrint = function (url, options) {
        return SmartPrint.print(url, options);
    };
}
if (typeof window.smartPrintZPL === 'undefined') {
    window.smartPrintZPL = function (zpl, printer) {
        return SmartPrint.printZPL(zpl, printer);
    };
}
if (typeof window.smartPrintESC === 'undefined') {
    window.smartPrintESC = function (escpos, printer) {
        return SmartPrint.printESC(escpos, printer);
    };
}

// v1.5 — HTML/element/PDF shortcuts + run-anywhere action runner.
// All guarded against name collisions with the host app.
if (typeof window.smartPrintHTML === 'undefined') {
    window.smartPrintHTML = function (html, options) {
        return SmartPrint.printHTML(html, options);
    };
}
if (typeof window.smartPrintPdf === 'undefined') {
    window.smartPrintPdf = function (input, options) {
        return SmartPrint.printPdf(input, options);
    };
}
if (typeof window.printElement === 'undefined') {
    window.printElement = function (selector, options) {
        return SmartPrint.printElement(selector, options);
    };
}
if (typeof window.smartPrintRun === 'undefined') {
    window.smartPrintRun = function (name, input, overrides) {
        return SmartPrint.run(name, input, overrides);
    };
}
if (typeof window.smartPrintDefine === 'undefined') {
    window.smartPrintDefine = function (name, template) {
        return SmartPrint.define(name, template);
    };
}
