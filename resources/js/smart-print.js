/**
 * Laravel QZ Tray - Smart Print
 * Complete printing solution for Laravel with QZ Tray
 * Version: 2.0.1 (Fixed error handling)
 */
(function() {
    'use strict';

    const CONFIG = {
        ENDPOINT: (window.QZ_CONFIG && window.QZ_CONFIG.endpoint) || '/qz',
        DEBUG: (window.QZ_CONFIG && window.QZ_CONFIG.debug) || false,
        VERSION: '2.0.1',
        CONNECT_TIMEOUT: 10000,
        PRINT_TIMEOUT: 30000,
        PDF_TIMEOUT: 30000,
        MAX_RETRIES: 3,
        RETRY_DELAY: 1000,
        CERT_CACHE_DURATION: 3600000,
        PRINTER_CACHE_DURATION: 300000,
        STORAGE_KEYS: {
            CERTIFICATE: 'qz_certificate_v2',
            PRINTERS: 'qz_printers_v2',
            PRINTER_MAP: 'qz_printer_map_v2',
            SETTINGS: 'qz_settings_v2',
            PRINT_JOBS: 'qz_print_jobs_v2',
        },
        DEFAULT_SETTINGS: {
            autoConnect: true,
            rememberPrinters: true,
            showNotifications: true,
            fallbackToBrowser: true,
            enableHotkey: true,
            cachePrinters: true,
            debugMode: false,
        },
    };

    const state = {
        connected: false,
        printers: [],
        settings: {...CONFIG.DEFAULT_SETTINGS},
        printQueue: [],
        isProcessing: false,
        currentJob: null,
        eventListeners: new Map(),
    };

    function log(...args) {
        if (CONFIG.DEBUG || state.settings.debugMode) {
            console.log('[Smart-Print]', ...args);
        }
    }

    function error(...args) {
        console.error('[Smart-Print]', ...args);
    }

    function warn(...args) {
        console.warn('[Smart-Print]', ...args);
    }

    function emit(event, data = {}) {
        const fullEvent = `smartprint:${event}`;
        const eventObj = new CustomEvent(fullEvent, { detail: data });
        document.dispatchEvent(eventObj);

        if (state.eventListeners.has(event)) {
            state.eventListeners.get(event).forEach(callback => {
                try {
                    callback(data);
                } catch (e) {
                    console.error('Event listener error:', e);
                }
            });
        }

        log(`Event emitted: ${event}`, data);
    }

    async function waitForQz() {
        return new Promise((resolve, reject) => {
            if (typeof qz !== 'undefined' && qz.security) {
                resolve();
                return;
            }

            const maxAttempts = 50;
            let attempts = 0;

            const interval = setInterval(() => {
                attempts++;

                if (typeof qz !== 'undefined' && qz.security) {
                    clearInterval(interval);
                    resolve();
                } else if (attempts >= maxAttempts) {
                    clearInterval(interval);
                    reject(new Error('QZ Tray library not loaded'));
                }
            }, 100);
        });
    }

    function setupSecurity() {
        qz.security.setCertificatePromise(function() {
            return new Promise((resolve, reject) => {
                const cached = localStorage.getItem(CONFIG.STORAGE_KEYS.CERTIFICATE);
                if (cached) {
                    log('Using cached certificate');
                    resolve(cached);
                    return;
                }

                log('Fetching certificate from server');

                fetch(`${CONFIG.ENDPOINT}/certificate`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`Certificate request failed: ${response.status}`);
                        }
                        return response.text();
                    })
                    .then(certificate => {
                        localStorage.setItem(CONFIG.STORAGE_KEYS.CERTIFICATE, certificate);
                        resolve(certificate);
                    })
                    .catch(err => {
                        reject(new Error(`Failed to load certificate: ${err.message}`));
                    });
            });
        });

        qz.security.setSignaturePromise(function(data) {
            return new Promise((resolve, reject) => {
                log('Requesting signature');

                fetch(`${CONFIG.ENDPOINT}/sign`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ data: data })
                })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`Signature request failed: ${response.status}`);
                        }
                        return response.text();
                    })
                    .then(signature => {
                        resolve(signature);
                    })
                    .catch(err => {
                        reject(new Error(`Failed to get signature: ${err.message}`));
                    });
            });
        });
    }

    async function connect() {
        if (state.connected) {
            log('Already connected');
            return true;
        }

        emit('connecting');

        try {
            log('Connecting to QZ Tray...');

            // FIX: Use proper connection options
            await qz.websocket.connect({
                retries: 1,  // Reduced from 3 to prevent port scanning
                delay: 500,
                timeout: CONFIG.CONNECT_TIMEOUT,
            });

            state.connected = true;
            emit('connected');
            log('Connected to QZ Tray');

            return true;
        } catch (err) {
            emit('connection-failed', { error: err.message });
            console.error('Connection failed:', err.message);

            return false;
        }
    }

    async function getPrinters() {
        if (state.settings.cachePrinters) {
            const cached = localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTERS);
            if (cached) {
                try {
                    const data = JSON.parse(cached);
                    if (Date.now() - data.timestamp < CONFIG.PRINTER_CACHE_DURATION) {
                        state.printers = data.printers;
                        log('Loaded printers from cache:', state.printers.length);
                        return state.printers;
                    }
                } catch (e) {}
            }
        }

        try {
            log('Fetching printers from server...');

            const response = await fetch(`${CONFIG.ENDPOINT}/printers`);

            if (!response.ok) {
                throw new Error(`Failed to fetch printers: ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.printers) {
                state.printers = Array.isArray(data.printers) ? data.printers : [];

                if (state.settings.cachePrinters) {
                    localStorage.setItem(CONFIG.STORAGE_KEYS.PRINTERS, JSON.stringify({
                        printers: state.printers,
                        timestamp: Date.now(),
                    }));
                }

                emit('printers-loaded', { printers: state.printers, cached: false });
                log('Printers loaded from server:', state.printers.length);

                return state.printers;
            }

            return [];
        } catch (err) {
            emit('printers-error', { error: err.message });
            console.error('Failed to load printers:', err);

            try {
                if (state.connected) {
                    const qzPrinters = await qz.printers.find();
                    if (qzPrinters && qzPrinters.length > 0) {
                        state.printers = qzPrinters;
                        return state.printers;
                    }
                }
            } catch (qzError) {}

            return [];
        }
    }

    async function getPrinterForPath(path = window.location.pathname) {
        const activeElement = document.activeElement;
        if (activeElement && activeElement.dataset && activeElement.dataset.qzPrinter) {
            return activeElement.dataset.qzPrinter;
        }

        if (state.settings.rememberPrinters) {
            const map = JSON.parse(
                localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTER_MAP) || '{}'
            );

            if (map[path]) {
                return map[path];
            }

            for (const [key, printer] of Object.entries(map)) {
                if (key.endsWith('/*') && path.startsWith(key.slice(0, -2))) {
                    return printer;
                }
            }
        }

        try {
            const response = await fetch(`${CONFIG.ENDPOINT}/printer${encodeURIComponent(path)}`);
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.printer) {
                    return data.printer;
                }
            }
        } catch (e) {
            log('Failed to get printer from server:', e.message);
        }

        if (state.connected) {
            try {
                const printers = await qz.printers.find();
                if (printers && printers.length > 0) {
                    const defaultPrinter = await qz.printers.getDefault();
                    return defaultPrinter || printers[0];
                }
            } catch (e) {
                console.error('Failed to get default printer:', e);
            }
        }

        return null;
    }

    async function savePrinterForPath(printer, path = window.location.pathname) {
        if (!printer) return;

        if (state.settings.rememberPrinters) {
            const map = JSON.parse(
                localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTER_MAP) || '{}'
            );
            map[path] = printer;
            localStorage.setItem(
                CONFIG.STORAGE_KEYS.PRINTER_MAP,
                JSON.stringify(map)
            );
        }

        try {
            await fetch(`${CONFIG.ENDPOINT}/printer`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    printer: printer,
                    path: path,
                })
            });
        } catch (e) {
            console.warn('Failed to save printer to server:', e);
        }

        emit('printer-saved', { path, printer });
        log(`Printer "${printer}" saved for path "${path}"`);
    }

    function blobToBase64(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => {
                const base64 = reader.result.split(',')[1];
                resolve(base64);
            };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }

    async function fetchPdf(url, attempt = 1) {
        const controller = new AbortController();
        const timeoutId = setTimeout(
            () => controller.abort(),
            CONFIG.PDF_TIMEOUT
        );

        try {
            log(`Fetching PDF from: ${url} (attempt ${attempt})`);

            const headers = {
                'Accept': 'application/pdf, */*',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Print-Request': 'true',
                'X-Print-Attempt': attempt.toString(),
            };

            const csrfToken = document.querySelector('meta[name="csrf-token"]');
            if (csrfToken) {
                headers['X-CSRF-TOKEN'] = csrfToken.getAttribute('content');
            }

            const response = await fetch(url, {
                headers: headers,
                credentials: 'include',
                signal: controller.signal,
            });

            clearTimeout(timeoutId);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const contentType = response.headers.get('content-type') || '';
            const contentDisposition = response.headers.get('content-disposition') || '';

            const isPdf = contentType.includes('pdf') ||
                contentDisposition.includes('.pdf') ||
                contentDisposition.includes('attachment');

            if (!isPdf) {
                const text = await response.text();
                if (text.includes('%PDF') || text.includes('PDF')) {
                    log('PDF detected without proper headers');
                } else {
                    throw new Error('Response is not a PDF');
                }

                const blob = new Blob([text], { type: 'application/pdf' });
                return await blobToBase64(blob);
            }

            const blob = await response.blob();
            const base64Data = await blobToBase64(blob);

            log(`PDF fetched successfully (${blob.size} bytes)`);
            return base64Data;

        } catch (err) {
            clearTimeout(timeoutId);

            if (err.name === 'AbortError') {
                throw new Error('PDF download timeout');
            }

            throw err;
        }
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    async function smartPrint(url, options = {}) {
        const jobId = 'job_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const startTime = Date.now();

        const job = {
            id: jobId,
            url: url,
            options: {
                printer: options.printer || null,
                type: options.type || 'pdf',
                copies: parseInt(options.copies) || 1,
                rawData: options.rawData || null,
                ...options
            },
            status: 'queued',
            path: window.location.pathname,
            startTime: startTime,
            attempts: 0,
        };

        state.printQueue.push(job);
        emit('job-queued', { job });
        log(`Job queued: ${jobId}`);

        processQueue();

        return jobId;
    }

    async function processQueue() {
        if (state.isProcessing || state.printQueue.length === 0) {
            return;
        }

        state.isProcessing = true;

        while (state.printQueue.length > 0) {
            const job = state.printQueue.shift();
            state.currentJob = job;

            await processJob(job);
        }

        state.currentJob = null;
        state.isProcessing = false;
    }

    async function processJob(job) {
        job.attempts++;
        job.status = 'processing';
        emit('job-processing', { job });

        try {
            log(`Processing job: ${job.id} (attempt ${job.attempts})`);

            if (!state.connected && !(await connect())) {
                throw new Error('Cannot connect to QZ Tray');
            }

            const printer = job.options.printer || await getPrinterForPath(job.path);
            if (!printer) {
                throw new Error('No printer selected');
            }

            const config = qz.configs.create(printer);

            let printData;

            if (job.options.type === 'zpl' || job.options.type === 'escpos' || job.options.type === 'raw') {
                printData = [{
                    type: 'raw',
                    data: job.options.rawData || job.url,
                    options: {
                        language: job.options.type === 'zpl' ? 'ZPL' : 'ESC/POS'
                    }
                }];
            } else {
                const pdfData = await fetchPdf(job.url, job.attempts);

                printData = [{
                    type: 'pdf',
                    format: 'base64',
                    data: pdfData,
                }];
            }

            if (job.options.copies > 1) {
                printData[0].copies = job.options.copies;
            }

            const result = await qz.print(config, printData);

            job.status = 'completed';
            job.endTime = Date.now();
            job.duration = job.endTime - job.startTime;
            job.result = result;

            try {
                await fetch(`${CONFIG.ENDPOINT}/print`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        url: job.url,
                        printer: printer,
                        type: job.options.type,
                        copies: job.options.copies,
                        path: job.path,
                        job_id: job.id,
                    })
                });
            } catch (e) {
                console.warn('Failed to notify server:', e);
            }

            emit('job-completed', { job });
            log(`Job completed: ${job.id} in ${job.duration}ms`);

        } catch (err) {
            job.status = 'failed';
            job.error = err.message;
            job.endTime = Date.now();
            job.duration = job.endTime - job.startTime;

            emit('job-failed', { job, error: err.message });
            console.error(`Job failed: ${job.id}`, err);

            if (job.attempts < CONFIG.MAX_RETRIES) {
                log(`Retrying job ${job.id} (attempt ${job.attempts + 1})`);
                state.printQueue.unshift(job);

                await new Promise(resolve =>
                    setTimeout(resolve, CONFIG.RETRY_DELAY * job.attempts)
                );
            } else {
                if (state.settings.fallbackToBrowser) {
                    fallbackPrint(job);
                }
            }
        }
    }

    function fallbackPrint(job) {
        log('Falling back to browser printing');

        if (job.options.type === 'pdf' || !job.options.type) {
            const printWindow = window.open(job.url, '_blank');
            if (printWindow) {
                printWindow.onload = function() {
                    try {
                        printWindow.print();
                    } catch (e) {
                        console.warn('Browser print failed:', e);
                    }
                };
            }
        } else {
            window.open(job.url, '_blank');
        }

        emit('fallback-print', { job });
    }

    function setupEventListeners() {
        document.addEventListener('click', function(event) {
            const element = event.target.closest('[data-qz-print]');

            if (!element) return;

            event.preventDefault();

            const url = element.dataset.qzPrint;
            const options = {
                printer: element.dataset.qzPrinter,
                type: element.dataset.qzType || 'pdf',
                copies: parseInt(element.dataset.qzCopies) || 1,
            };

            if (url) {
                smartPrint(url, options);
            }
        });

        if (state.settings.enableHotkey) {
            document.addEventListener('keydown', function(event) {
                if (event.ctrlKey && event.shiftKey && event.key === 'P') {
                    event.preventDefault();
                    showPrinterSwitcher();
                }
            });
        }
    }

    async function showPrinterSwitcher() {
        if (!state.connected && !(await connect())) {
            if (state.settings.showNotifications) {
                alert('Cannot connect to QZ Tray');
            }
            return;
        }

        if (state.printers.length === 0) {
            await getPrinters();
        }

        const currentPrinter = await getPrinterForPath();
        const printerNames = state.printers.map(p =>
            typeof p === 'string' ? p : (p.name || p)
        ).join('\n');

        const newPrinter = prompt(
            `🖨️ Current Printer: ${currentPrinter || 'Not set'}\n\n` +
            `Available Printers:\n${printerNames}\n\n` +
            `Enter printer name:`,
            currentPrinter
        );

        if (newPrinter) {
            const printerExists = state.printers.some(p =>
                (typeof p === 'string' && p === newPrinter) ||
                (p.name && p.name === newPrinter)
            );

            if (printerExists) {
                await savePrinterForPath(newPrinter);

                if (state.settings.showNotifications) {
                    alert(`✅ Printer "${newPrinter}" saved for this page`);
                }
            } else {
                if (state.settings.showNotifications) {
                    alert('❌ Printer not found in available printers');
                }
            }
        }
    }

    function loadSettings() {
        try {
            const saved = localStorage.getItem(CONFIG.STORAGE_KEYS.SETTINGS);
            if (saved) {
                state.settings = { ...state.settings, ...JSON.parse(saved) };
            }
        } catch (e) {
            console.error('Failed to load settings:', e);
        }
    }

    function saveSettings() {
        try {
            localStorage.setItem(
                CONFIG.STORAGE_KEYS.SETTINGS,
                JSON.stringify(state.settings)
            );
        } catch (e) {
            console.error('Failed to save settings:', e);
        }
    }

    function initAutoPrint() {
        const elements = document.querySelectorAll('[data-qz-auto-print]');

        elements.forEach(element => {
            const url = element.dataset.qzAutoPrint;
            const delay = parseInt(element.dataset.qzDelay) || 1000;
            const printer = element.dataset.qzPrinter;

            if (url) {
                setTimeout(() => {
                    smartPrint(url, { printer });
                }, delay);
            }
        });
    }

    const SmartPrintAPI = {
        print: smartPrint,

        printRaw: function(data, type = 'zpl', printer = null) {
            return smartPrint(data, {
                type: type,
                printer: printer,
                rawData: data,
            });
        },

        printZPL: function(zpl, printer = null) {
            return this.printRaw(zpl, 'zpl', printer);
        },

        printESC: function(escpos, printer = null) {
            return this.printRaw(escpos, 'escpos', printer);
        },

        getPrinters: getPrinters,
        getCurrentPrinter: () => getPrinterForPath(),
        setPrinter: savePrinterForPath,
        showPrinterSwitcher: showPrinterSwitcher,

        connect: connect,
        disconnect: function() {
            if (state.connected && qz.websocket) {
                return qz.websocket.disconnect().then(() => {
                    state.connected = false;
                    emit('disconnected');
                });
            }
            return Promise.resolve();
        },

        isConnected: () => state.connected,
        getStatus: function() {
            return {
                connected: state.connected,
                printers: state.printers.length,
                queueLength: state.printQueue.length,
                isProcessing: state.isProcessing,
                currentJob: state.currentJob,
            };
        },

        getQueue: () => [...state.printQueue],
        clearQueue: function() {
            state.printQueue = [];
            emit('queue-cleared');
        },

        getSettings: () => ({ ...state.settings }),
        updateSettings: function(newSettings) {
            state.settings = { ...state.settings, ...newSettings };
            saveSettings();
            emit('settings-updated', { settings: state.settings });
            return state.settings;
        },

        on: function(event, callback) {
            if (!state.eventListeners.has(event)) {
                state.eventListeners.set(event, []);
            }
            state.eventListeners.get(event).push(callback);
            return this;
        },

        off: function(event, callback) {
            if (state.eventListeners.has(event)) {
                const listeners = state.eventListeners.get(event);
                const index = listeners.indexOf(callback);
                if (index > -1) {
                    listeners.splice(index, 1);
                }
            }
            return this;
        },

        clearCache: function() {
            Object.values(CONFIG.STORAGE_KEYS).forEach(key => {
                localStorage.removeItem(key);
            });
            emit('cache-cleared');
            log('Cache cleared');
        },

        version: CONFIG.VERSION,
    };

    async function initialize() {
        try {
            loadSettings();
            await waitForQz();
            setupSecurity();
            setupEventListeners();

            if (state.settings.autoConnect) {
                await connect();
            }

            if (state.connected) {
                await getPrinters();
            }

            initAutoPrint();
            emit('ready', { version: CONFIG.VERSION });
            log('Smart-Print initialized successfully');

        } catch (err) {
            console.error('Initialization failed:', err);
            emit('init-failed', { error: err.message });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    window.SmartPrint = SmartPrintAPI;
    window.smartPrint = smartPrint;
    window.smartPrintZPL = SmartPrintAPI.printZPL;
    window.smartPrintESC = SmartPrintAPI.printESC;

})();
