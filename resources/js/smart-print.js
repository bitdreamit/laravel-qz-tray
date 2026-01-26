window.SmartPrint = (() => {
    const STORAGE_PREFIX = 'smart_printer:';
    const GLOBAL_KEY = 'smart_printer_global';

    const state = {
        qzReady: false,
        connecting: false,
        printers: [],
        currentPrinter: null,
        queue: [],
        failedQueue: [],
        channel: new BroadcastChannel('smart-print')
    };

    const pathKey = () => location.pathname.split('/').slice(0, 2).join('/');

    // ============================
    // Device-based default printers
    // ============================
    const DEVICE_PRINTERS = {
        'LAB-PC-01': { '/receipts': 'Zebra ZD420', '/invoices': 'Laser Printer' },
        'LAB-PC-02': { '/receipts': 'Thermal Printer' },
        '/admin': { '/admin/reports': 'Office Laser Printer' }
    };

    function getDeviceName() {
        return window.location.hostname.toUpperCase(); // use hostname as device ID
    }

    function autoAssignPrinter() {
        const device = getDeviceName();
        const route = pathKey();
        if (DEVICE_PRINTERS[device] && DEVICE_PRINTERS[device][route]) {
            const printer = DEVICE_PRINTERS[device][route];
            SmartPrint.setPrinter(printer); // sets and broadcasts
            console.log(`Auto-assigned printer "${printer}" for ${device} @ ${route}`);
        }
    }

    // ============================
    // QZ Security
    // ============================
    function setupSecurity() {
        if (!window.qz) return;
        qz.security.setCertificatePromise(resolve =>
            fetch('/qz/certificate', { cache: 'no-store' })
                .then(r => r.text())
                .then(resolve)
        );
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(toSign => (resolve, reject) =>
            fetch('/qz/sign', {
                method: 'POST',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                },
                body: JSON.stringify({ data: toSign })
            })
                .then(r => r.ok ? r.text().then(resolve) : r.text().then(reject))
                .catch(reject)
        );
    }

    // ============================
    // Connect QZ
    // ============================
    async function connectQZ(retries = 2) {
        if (!window.qz) return false;
        if (qz.websocket.isActive()) return true;
        if (state.connecting) return false;
        state.connecting = true;
        setupSecurity();
        try {
            await qz.websocket.connect();
            state.qzReady = true;
            state.printers = await qz.printers.find();
            restorePrinter();
            return true;
        } catch {
            if (retries > 0) {
                window.location.assign('qz:launch');
                await new Promise(r => setTimeout(r, 1200));
                state.connecting = false;
                return connectQZ(retries - 1);
            }
            state.qzReady = false;
            return false;
        } finally { state.connecting = false; }
    }

    // ============================
    // Printer Memory
    // ============================
    function restorePrinter() {
        const saved = localStorage.getItem(STORAGE_PREFIX + pathKey()) || localStorage.getItem(GLOBAL_KEY);
        if (saved && state.printers.includes(saved)) state.currentPrinter = saved;
    }

    function rememberPrinter(printer, scope = 'path') {
        state.currentPrinter = printer;
        localStorage.setItem(scope === 'global' ? GLOBAL_KEY : STORAGE_PREFIX + pathKey(), printer);
        state.channel.postMessage({ printer });
    }

    state.channel.onmessage = e => { if (e.data.printer) state.currentPrinter = e.data.printer; };

    // ============================
    // Queue Management
    // ============================
    const pdfProfiles = { default: { width: 210, height: 297, scale: 1.0 }, small: { width: 210, height: 297, scale: 0.8 } };

    function enqueue(job) { state.queue.push(job); updateQueueUI(); processQueue(); }

    async function processQueue() {
        if (!state.queue.length) return;
        const job = state.queue.shift();
        try {
            if (await connectQZ()) await printQZ(job);
            else offlineBuffer(job);
        } catch { offlineBuffer(job); }
        updateQueueUI();
    }

    function offlineBuffer(job) {
        state.failedQueue.push(job);
        const offline = JSON.parse(localStorage.getItem('sp_offline_queue') || '[]');
        offline.push(job);
        localStorage.setItem('sp_offline_queue', JSON.stringify(offline));
        console.warn('QZ Tray offline – job stored for retry.');
    }

    function retryOffline() {
        const offline = JSON.parse(localStorage.getItem('sp_offline_queue') || '[]');
        offline.forEach(job => enqueue(job));
        localStorage.removeItem('sp_offline_queue');
    }

    async function printQZ(job) {
        const printer = job.printer || state.currentPrinter;
        if (!printer) return openPrinterModal(job);
        rememberPrinter(printer);
        const cfgOpts = { copies: job.copies || 1 };
        if (job.type === 'pdf' && job.profile) {
            const profile = pdfProfiles[job.profile] || pdfProfiles.default;
            cfgOpts.size = { width: profile.width, height: profile.height };
            cfgOpts.scaleContent = profile.scale;
        }
        const cfg = qz.configs.create(printer, cfgOpts);
        let payload;
        switch (job.type) {
            case 'pdf': payload = [{ type: 'pdf', data: job.url }]; break;
            case 'html': payload = [{ type: 'html', data: job.data }]; break;
            case 'zpl':
            case 'raw': payload = [{ type: 'raw', format: 'command', data: job.data }]; break;
            default: return fallback(job);
        }
        try { await qz.print(cfg, payload); } catch { fallback(job); }
    }

    function fallback(job) {
        if (job.type === 'pdf' && job.url) window.open(job.url, '_blank').print();
        else if (job.type === 'html') { const w = window.open(); w.document.write(job.data); w.print(); }
        else alert('Silent printing unavailable. Install QZ Tray.');
    }

    function openPrinterModal(job) {
        const modal = document.createElement('div'); modal.className = 'sp-modal';
        modal.innerHTML = `<div class="sp-box"><h3>Select Printer</h3>${state.printers.map(p => `<button>${p}</button>`).join('')}</div>`;
        document.body.appendChild(modal);
        modal.querySelectorAll('button').forEach(btn => {
            btn.onclick = () => { rememberPrinter(btn.innerText); modal.remove(); enqueue(job); };
        });
    }

    // ============================
    // DOM Binding
    // ============================
    function bind() {
        document.addEventListener('click', e => {
            const el = e.target.closest('[data-smart-print]');
            if (!el) return;
            enqueue({ type: el.dataset.type, url: el.dataset.url, data: el.dataset.data, printer: el.dataset.printer, copies: el.dataset.copies, profile: el.dataset.profile });
        });
        document.querySelectorAll('[data-auto-print="true"]').forEach(el => {
            enqueue({ type: el.dataset.type, url: el.dataset.url, data: el.dataset.data, printer: el.dataset.printer, copies: el.dataset.copies, profile: el.dataset.profile });
        });
    }

    function updateQueueUI() {
        const container = document.getElementById('sp-queue-list'); if (!container) return;
        container.innerHTML = '';
        state.queue.forEach(job => { const li = document.createElement('li'); li.innerHTML = `<strong>Queued:</strong> ${job.type} ${job.url || 'Raw Data'}`; container.appendChild(li); });
        state.failedQueue.forEach((job, i) => { const li = document.createElement('li'); li.innerHTML = `<strong style="color:red;">Failed:</strong> ${job.type} ${job.url || 'Raw Data'} <button style="font-size:10px;" onclick="SmartPrint.retryJob(${i})">Retry</button>`; container.appendChild(li); });
    }

    function retryJob(index) { const job = state.failedQueue.splice(index, 1)[0]; enqueue(job); updateQueueUI(); }

    document.addEventListener('keydown', e => { if (e.ctrlKey && e.shiftKey && e.key === 'P') openPrinterModal({}); });

    setInterval(() => { if (window.qz && !qz.websocket.isActive()) connectQZ(1); }, 5000);

    return {
        init: () => { bind(); autoAssignPrinter(); connectQZ(); retryOffline(); updateQueueUI(); },
        print: (data, options = {}) => { enqueue({ data, ...options }); updateQueueUI(); },
        setPrinter: rememberPrinter,
        listPrinters: async () => { await connectQZ(); return state.printers; },
        retryOffline,
        retryJob
    };
})();
