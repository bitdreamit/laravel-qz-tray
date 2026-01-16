/**
 * Laravel QZ Tray - Smart Print
 * Official QZ Tray API Integration
 * Version: 2.1.0 (Official API Compliant)
 */
(function() {
    'use strict';

    const CONFIG = {
        ENDPOINT: (window.QZ_CONFIG && window.QZ_CONFIG.endpoint) || '/qz',
        DEBUG: (window.QZ_CONFIG && window.QZ_CONFIG.debug) || false,
        VERSION: '2.1.0',
        PRINT_TIMEOUT: 30000,
        PDF_TIMEOUT: 30000,
        MAX_RETRIES: 2,
        STORAGE_KEYS: {
            CERTIFICATE: 'qz_certificate_official',
            PRINTERS: 'qz_printers_official',
            PRINTER_MAP: 'qz_printer_map_official',
            SETTINGS: 'qz_settings_official',
        },
        DEFAULT_SETTINGS: {
            autoConnect: false,
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

    // Wait for QZ Tray library
    async function waitForQz() {
        return new Promise((resolve) => {
            if (typeof qz !== 'undefined' && qz.security) {
                resolve();
                return;
            }

            const maxAttempts = 30;
            let attempts = 0;

            const check = setInterval(() => {
                attempts++;

                if (typeof qz !== 'undefined' && qz.security) {
                    clearInterval(check);
                    resolve();
                } else if (attempts >= maxAttempts) {
                    clearInterval(check);
                    console.error('QZ Tray library not loaded');
                    resolve();
                }
            }, 100);
        });
    }

    // Setup security certificates (Official QZ Tray way)
    function setupSecurity() {
        log('Setting up QZ Tray security...');

        qz.security.setCertificatePromise(function() {
            return new Promise((resolve, reject) => {
                // Try cache first
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
                            throw new Error(`HTTP ${response.status}`);
                        }
                        return response.text();
                    })
                    .then(certificate => {
                        localStorage.setItem(CONFIG.STORAGE_KEYS.CERTIFICATE, certificate);
                        resolve(certificate);
                    })
                    .catch(error => {
                        console.error('Failed to load certificate:', error);
                        reject(error);
                    });
            });
        });

        qz.security.setSignaturePromise(function (data) {
            log('Requesting signature');

            return fetch(CONFIG.ENDPOINT + '/sign', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify({ data: data })
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.text();
                });
        });

        log('Security setup complete');
    }

    // Connect to QZ Tray (Official API)
    async function connect() {
        if (state.connected) {
            log('Already connected to QZ Tray');
            return true;
        }

        emit('connecting');

        try {
            log('Connecting to QZ Tray...');

            // Use official QZ Tray connection method
            // Note: QZ Tray handles its own retry logic and port discovery
            await qz.websocket.connect();

            state.connected = true;
            emit('connected');
            log('✅ Connected to QZ Tray');

            return true;

        } catch (error) {
            console.error('Connection failed:', error.message);

            // Check if already connected (QZ Tray might throw error but still be connected)
            try {
                // Try to get printers - if this works, we're connected
                const printers = await qz.printers.find();
                if (printers && printers.length > 0) {
                    state.connected = true;
                    emit('connected');
                    log('✅ QZ Tray connection verified via printer list');
                    return true;
                }
            } catch (e) {
                // Not connected
            }

            emit('connection-failed', { error: error.message });
            return false;
        }
    }

    // Get printers (with caching)
    async function getPrinters() {
        // Check cache first
        if (state.settings.cachePrinters) {
            const cached = localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTERS);
            if (cached) {
                try {
                    const data = JSON.parse(cached);
                    if (Date.now() - data.timestamp < 300000) { // 5 minutes
                        state.printers = data.printers;
                        log('Loaded printers from cache:', state.printers.length);
                        return state.printers;
                    }
                } catch (e) {
                    // Cache invalid
                }
            }
        }

        try {
            log('Getting printers from QZ Tray...');

            // Ensure connected
            if (!state.connected && !(await connect())) {
                throw new Error('Not connected to QZ Tray');
            }

            // Get printers from QZ Tray
            const qzPrinters = await qz.printers.find();

            if (qzPrinters && qzPrinters.length > 0) {
                state.printers = qzPrinters;

                // Cache results
                if (state.settings.cachePrinters) {
                    localStorage.setItem(CONFIG.STORAGE_KEYS.PRINTERS, JSON.stringify({
                        printers: state.printers,
                        timestamp: Date.now(),
                    }));
                }

                emit('printers-loaded', { printers: state.printers });
                log(`Found ${state.printers.length} printer(s)`);

                return state.printers;
            }

            return [];

        } catch (error) {
            console.error('Failed to get printers:', error);
            emit('printers-error', { error: error.message });
            return [];
        }
    }

    // Get printer for current path
    async function getPrinterForPath(path = window.location.pathname) {
        // Check data attribute first
        const activeElement = document.activeElement;
        if (activeElement && activeElement.dataset && activeElement.dataset.qzPrinter) {
            return activeElement.dataset.qzPrinter;
        }

        // Check localStorage cache
        if (state.settings.rememberPrinters) {
            const map = JSON.parse(
                localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTER_MAP) || '{}'
            );

            // Exact match
            if (map[path]) {
                return map[path];
            }

            // Wildcard match
            for (const [key, printer] of Object.entries(map)) {
                if (key.endsWith('/*') && path.startsWith(key.slice(0, -2))) {
                    return printer;
                }
            }
        }

        // Get from server
        try {
            const response = await fetch(`${CONFIG.ENDPOINT}/printer${encodeURIComponent(path)}`);
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.printer) {
                    return data.printer;
                }
            }
        } catch (e) {
            // Ignore error
        }

        // Get default from QZ Tray
        if (state.connected || await connect()) {
            try {
                const printers = await getPrinters();
                if (printers.length > 0) {
                    // Try to get system default
                    try {
                        const defaultPrinter = await qz.printers.getDefault();
                        if (defaultPrinter) {
                            return defaultPrinter;
                        }
                    } catch (e) {
                        // Use first printer
                    }

                    const firstPrinter = printers[0];
                    return typeof firstPrinter === 'string' ? firstPrinter : firstPrinter.name;
                }
            } catch (e) {
                console.error('Failed to get default printer:', e);
            }
        }

        return null;
    }

    // Save printer for path
    async function savePrinterForPath(printer, path = window.location.pathname) {
        if (!printer) return;

        // Save to localStorage
        if (state.settings.rememberPrinters) {
            const map = JSON.parse(
                localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTER_MAP) || '{}'
            );
            map[path] = printer;
            localStorage.setItem(CONFIG.STORAGE_KEYS.PRINTER_MAP, JSON.stringify(map));
        }

        // Save to server (optional)
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

    // Convert blob to base64
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

    // Fetch PDF from URL
    async function fetchPdf(url, attempt = 1) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), CONFIG.PDF_TIMEOUT);

        try {
            log(`Fetching PDF: ${url}`);

            const headers = {
                'Accept': 'application/pdf, */*',
                'X-Requested-With': 'XMLHttpRequest',
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
                throw new Error(`HTTP ${response.status}`);
            }

            const contentType = response.headers.get('content-type') || '';

            if (!contentType.includes('pdf')) {
                // Check if it might be PDF anyway
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
            return await blobToBase64(blob);

        } catch (error) {
            clearTimeout(timeoutId);
            throw error;
        }
    }

    // Main print function
    async function smartPrint(url, options = {}) {
        const jobId = 'job_' + Date.now();
        const startTime = Date.now();

        const job = {
            id: jobId,
            url: url,
            options: {
                printer: options.printer || null,
                type: options.type || 'pdf',
                copies: parseInt(options.copies) || 1,
                ...options
            },
            status: 'queued',
            startTime: startTime,
            attempts: 0,
        };

        // Add to queue and process
        state.printQueue.push(job);
        emit('job-queued', { job });

        processQueue();

        return jobId;
    }

    // Process print queue
    async function processQueue() {
        if (state.isProcessing || state.printQueue.length === 0) {
            return;
        }

        state.isProcessing = true;

        while (state.printQueue.length > 0) {
            const job = state.printQueue.shift();
            await processJob(job);
        }

        state.isProcessing = false;
    }

    // Process individual job
    async function processJob(job) {
        job.attempts++;
        job.status = 'processing';
        emit('job-processing', { job });

        try {
            log(`Printing job ${job.id}...`);

            // Ensure connected
            if (!state.connected && !(await connect())) {
                throw new Error('Cannot connect to QZ Tray');
            }

            // Get printer
            const printer = job.options.printer || await getPrinterForPath();
            if (!printer) {
                throw new Error('No printer selected');
            }

            // Create QZ config
            const config = qz.configs.create(printer);

            // Prepare print data
            let printData;

            if (job.options.type === 'zpl' || job.options.type === 'escpos') {
                // Raw printing
                printData = [{
                    type: 'raw',
                    data: job.url, // For raw printing, URL contains the data
                    options: {
                        language: job.options.type === 'zpl' ? 'ZPL' : 'ESC/POS'
                    }
                }];
            } else {
                // PDF printing
                const pdfData = await fetchPdf(job.url, job.attempts);
                printData = [{
                    type: 'pdf',
                    format: 'base64',
                    data: pdfData,
                }];
            }

            // Set copies
            if (job.options.copies > 1) {
                printData[0].copies = job.options.copies;
            }

            // Execute print
            await qz.print(config, printData);

            // Update job status
            job.status = 'completed';
            job.duration = Date.now() - job.startTime;

            emit('job-completed', { job });
            log(`✅ Printed successfully in ${job.duration}ms`);

        } catch (error) {
            job.status = 'failed';
            job.error = error.message;
            job.duration = Date.now() - job.startTime;

            emit('job-failed', { job, error: error.message });
            console.error(`Print failed: ${error.message}`);

            // Retry logic
            if (job.attempts < CONFIG.MAX_RETRIES) {
                log(`Retrying job ${job.id} (attempt ${job.attempts + 1})`);
                state.printQueue.unshift(job);

                // Wait before retry
                await new Promise(resolve => setTimeout(resolve, 1000 * job.attempts));
            } else {
                // Fallback to browser printing
                if (state.settings.fallbackToBrowser) {
                    fallbackPrint(job);
                }
            }
        }
    }

    // Fallback to browser printing
    function fallbackPrint(job) {
        log('Falling back to browser printing');

        if (job.options.type === 'pdf' || !job.options.type) {
            const printWindow = window.open(job.url, '_blank');
            if (printWindow) {
                printWindow.onload = function() {
                    printWindow.print();
                };
            }
        } else {
            window.open(job.url, '_blank');
        }

        emit('fallback-print', { job });
    }

    // Setup event listeners
    function setupEventListeners() {
        // Handle data-qz-print clicks
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

        // Hotkey for printer switching (Ctrl+Shift+P)
        if (state.settings.enableHotkey) {
            document.addEventListener('keydown', function(event) {
                if (event.ctrlKey && event.shiftKey && event.key === 'P') {
                    event.preventDefault();
                    showPrinterSwitcher();
                }
            });
        }
    }

    // Show printer switcher
    async function showPrinterSwitcher() {
        if (!state.connected && !(await connect())) {
            if (state.settings.showNotifications) {
                alert('Please connect to QZ Tray first');
            }
            return;
        }

        const printers = await getPrinters();
        if (printers.length === 0) {
            alert('No printers found');
            return;
        }

        const currentPrinter = await getPrinterForPath();
        const printerList = printers.map(p =>
            typeof p === 'string' ? p : p.name
        ).join('\n');

        const newPrinter = prompt(
            `Current Printer: ${currentPrinter || 'Not set'}\n\n` +
            `Available Printers:\n${printerList}\n\n` +
            `Enter printer name:`,
            currentPrinter
        );

        if (newPrinter) {
            const printerExists = printers.some(p =>
                (typeof p === 'string' && p === newPrinter) ||
                (p.name && p.name === newPrinter)
            );

            if (printerExists) {
                await savePrinterForPath(newPrinter);

                if (state.settings.showNotifications) {
                    alert(`Printer "${newPrinter}" saved for this page`);
                }
            } else {
                alert('Printer not found');
            }
        }
    }

    // Load settings
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

    // Initialize auto-print elements
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

    // Public API
    const SmartPrintAPI = {
        // Print functions
        print: smartPrint,

        // Raw printing
        printRaw: function(data, type = 'zpl', printer = null) {
            // For raw printing, we need to handle data differently
            // This would require a different approach
            console.warn('printRaw requires custom implementation');
            return null;
        },

        printZPL: function(zpl, printer = null) {
            // ZPL printing would need special handling
            console.warn('ZPL printing requires custom implementation');
            return null;
        },

        printESC: function(escpos, printer = null) {
            // ESC/POS printing would need special handling
            console.warn('ESC/POS printing requires custom implementation');
            return null;
        },

        // Printer management
        getPrinters: getPrinters,
        getCurrentPrinter: () => getPrinterForPath(),
        setPrinter: savePrinterForPath,
        showPrinterSwitcher: showPrinterSwitcher,

        // Connection
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

        // Status
        getStatus: function() {
            return {
                connected: state.connected,
                printers: state.printers.length,
                queueLength: state.printQueue.length,
                version: CONFIG.VERSION,
            };
        },

        // Queue
        getQueue: () => [...state.printQueue],
        clearQueue: function() {
            state.printQueue = [];
            emit('queue-cleared');
        },

        // Settings
        getSettings: () => ({ ...state.settings }),
        updateSettings: function(newSettings) {
            state.settings = { ...state.settings, ...newSettings };
            localStorage.setItem(CONFIG.STORAGE_KEYS.SETTINGS, JSON.stringify(state.settings));
            return state.settings;
        },

        // Events
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

        // Utilities
        clearCache: function() {
            Object.values(CONFIG.STORAGE_KEYS).forEach(key => {
                localStorage.removeItem(key);
            });
            emit('cache-cleared');
        },

        version: CONFIG.VERSION,
    };

    // Initialize
    async function initialize() {
        try {
            log('Initializing Smart Print...');

            // Load settings
            loadSettings();

            // Wait for QZ Tray
            await waitForQz();

            // Setup security
            setupSecurity();

            // Setup event listeners
            setupEventListeners();

            // Initialize auto-print
            initAutoPrint();

            // Emit ready event
            emit('ready', { version: CONFIG.VERSION });
            log('✅ Smart Print initialized');

            // Auto-connect if enabled
            if (state.settings.autoConnect) {
                await connect();
            }

        } catch (error) {
            console.error('Initialization failed:', error);
            emit('init-failed', { error: error.message });
        }
    }

    // Start initialization
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    // Expose to window
    window.SmartPrint = SmartPrintAPI;
    window.smartPrint = smartPrint;

})();
