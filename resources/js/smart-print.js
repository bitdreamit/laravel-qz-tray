/**
 * Laravel QZ Tray - Smart Print Enhanced (Compatible with QZ v2.2.6)
 * Official QZ Tray API Integration with Printer Discovery
 * Version: 3.0.3 - QZ v2.2.6 Compatible
 */
(function() {
    'use strict';

    const CONFIG = {
        ENDPOINT: (window.QZ_CONFIG && window.QZ_CONFIG.endpoint) || '/qz',
        DEBUG: (window.QZ_CONFIG && window.QZ_CONFIG.debug) || false,
        VERSION: '3.0.3',
        PRINT_TIMEOUT: 30000,
        CONNECT_TIMEOUT: 15000,
        MAX_RETRIES: 3,
        PRINTER_CACHE_TTL: 300000,
        STORAGE_KEYS: {
            CERTIFICATE: 'qz_certificate_v3',
            PRINTERS: 'qz_printers_v3',
            PRINTER_DETAILS: 'qz_printer_details_v3',
            SETTINGS: 'qz_settings_v3',
            CONNECTION: 'qz_connection_v3',
        },
        DEFAULT_SETTINGS: {
            autoConnect: true,
            rememberPrinters: true,
            showNotifications: true,
            fallbackToBrowser: true,
            enableHotkey: true,
            cachePrinters: true,
            debugMode: false,
            autoDiscoverPrinters: true,
            showPrinterDetails: true,
            allowRemoteConnections: false,
            secureConnection: false, // Default to false for QZ v2.2.6
        },
    };

    const state = {
        connected: false,
        printers: [],
        printerDetails: new Map(),
        settings: {...CONFIG.DEFAULT_SETTINGS},
        printQueue: [],
        isProcessing: false,
        currentJob: null,
        eventListeners: new Map(),
        qzInitialized: false,
        connectionHost: 'localhost',
        connectionPort: 8181,
        connectionSecure: false,
        networkDevices: [],
        currentPrinter: null,
        connectionPromise: null,
        connectionMonitor: null,
        lastActivity: Date.now()
    };

    // Utility functions
    function log(...args) {
        if (CONFIG.DEBUG || state.settings.debugMode) {
            console.log('[SmartPrint]', ...args);
        }
    }

    function emit(event, data = {}) {
        log(`Event: ${event}`, data);
        state.lastActivity = Date.now();

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
    }

    // Wait for QZ Tray library
    async function waitForQz() {
        return new Promise((resolve) => {
            if (window.qz && window.qz.version) {
                log('QZ Tray library loaded:', window.qz.version);
                resolve();
                return;
            }

            const maxAttempts = 50;
            let attempts = 0;

            const check = setInterval(() => {
                attempts++;
                if (window.qz && window.qz.version) {
                    clearInterval(check);
                    log('QZ Tray library loaded after', attempts * 100, 'ms');
                    resolve();
                } else if (attempts >= maxAttempts) {
                    clearInterval(check);
                    log('QZ Tray library not found after timeout');
                    resolve();
                }
            }, 100);
        });
    }

    // Enhanced security setup for QZ v2.2.6
    function setupSecurity() {
        log('Setting up QZ Tray security for v2.2.6...');

        if (!window.qz || !window.qz.security) {
            log('QZ Tray security API not available');
            return;
        }

        qz.security.setCertificatePromise(function(resolve, reject) {
            const cached = sessionStorage.getItem(CONFIG.STORAGE_KEYS.CERTIFICATE);
            const cachedTime = sessionStorage.getItem(CONFIG.STORAGE_KEYS.CERTIFICATE + '_time');

            // Use cache if less than 1 hour old
            if (cached && cachedTime && (Date.now() - parseInt(cachedTime)) < 3600000) {
                log('Using cached certificate');
                resolve(cached);
                return;
            }

            log('Fetching certificate from server');
            fetch(`${CONFIG.ENDPOINT}/certificate`)
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Certificate fetch failed: ' + response.status);
                    }
                    return response.text();
                })
                .then(function(certificate) {
                    // Validate certificate
                    if (certificate && typeof certificate === 'string') {
                        // Cache in sessionStorage
                        sessionStorage.setItem(CONFIG.STORAGE_KEYS.CERTIFICATE, certificate);
                        sessionStorage.setItem(CONFIG.STORAGE_KEYS.CERTIFICATE + '_time', Date.now().toString());
                        resolve(certificate);
                    } else {
                        throw new Error('Invalid certificate format');
                    }
                })
                .catch(function(err) {
                    console.error('Certificate fetch error:', err);

                    // Try alternative endpoint
                    fetch('/qz/certificate')
                        .then(response => response.text())
                        .then(certificate => {
                            if (certificate) {
                                sessionStorage.setItem(CONFIG.STORAGE_KEYS.CERTIFICATE, certificate);
                                sessionStorage.setItem(CONFIG.STORAGE_KEYS.CERTIFICATE + '_time', Date.now().toString());
                                resolve(certificate);
                            } else {
                                reject(err);
                            }
                        })
                        .catch(() => {
                            // Fallback to cached even if old
                            if (cached) {
                                log('Using old cached certificate as fallback');
                                resolve(cached);
                            } else {
                                reject(err);
                            }
                        });
                });
        });

        qz.security.setSignaturePromise(function(toSign) {
            log('Requesting signature for data...', toSign ? 'Length: ' + toSign.length : 'empty');

            const headers = {
                'Content-Type': 'application/json'
            };

            // Add CSRF token if available
            const csrfToken = document.querySelector('meta[name="csrf-token"]');
            if (csrfToken) {
                headers['X-CSRF-TOKEN'] = csrfToken.getAttribute('content');
            }

            return fetch(`${CONFIG.ENDPOINT}/sign`, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({
                    data: toSign,
                    timestamp: Date.now(),
                    origin: window.location.origin
                })
            })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Signature failed: ' + response.status);
                    }
                    return response.text();
                })
                .then(function(signature) {
                    log('Signature received, length:', signature.length);
                    return signature;
                })
                .catch(function(error) {
                    console.error('Signature error:', error);
                    throw error;
                });
        });

        state.qzInitialized = true;
        log('Security setup complete');
    }

    // Enhanced connection status check
    function isConnected() {
        try {
            return state.connected && qz && qz.websocket && qz.websocket.isActive();
        } catch (e) {
            return false;
        }
    }

    // Simplified connection for QZ v2.2.6
    async function connect(host = state.connectionHost, port = state.connectionPort, secure = state.connectionSecure) {
        // If already connected, return true
        if (isConnected()) {
            if (!state.connected) {
                state.connected = true;
                emit('connected', { host, port, secure });
            }
            return true;
        }

        // If connection is in progress, wait for it
        if (state.connectionPromise) {
            return state.connectionPromise;
        }

        // Save connection settings
        state.connectionHost = host;
        state.connectionPort = port;
        state.connectionSecure = secure;

        log(`Connecting to ${host}:${port} (secure: ${secure})...`);
        emit('connecting', { host, port, secure });

        // Create connection promise
        state.connectionPromise = new Promise(async (resolve, reject) => {
            try {
                // Ensure QZ Tray is available
                await waitForQz();

                if (!window.qz || !window.qz.websocket) {
                    throw new Error('QZ Tray API not available');
                }

                // Ensure security is set up
                if (!state.qzInitialized) {
                    setupSecurity();
                }

                // Clear any existing connection
                try {
                    if (qz.websocket.isActive()) {
                        await qz.websocket.disconnect();
                    }
                    await new Promise(resolve => setTimeout(resolve, 300));
                } catch (e) {
                    // Ignore disconnect errors
                }

                // For QZ v2.2.6, use the specific connection method
                log('Using QZ v2.2.6 connection method');

                // Try different parameter combinations for QZ v2.2.6
                let connected = false;

                try {
                    // Method 1: Individual parameters (works with v2.2.6)
                    await qz.websocket.connect(host, port, secure, 3, 1000);
                    connected = true;
                } catch (error1) {
                    log('Method 1 failed:', error1.message);

                    try {
                        // Method 2: Without retries
                        await qz.websocket.connect(host, port, secure);
                        connected = true;
                    } catch (error2) {
                        log('Method 2 failed:', error2.message);

                        try {
                            // Method 3: Just host and port
                            await qz.websocket.connect(host, port);
                            connected = true;
                        } catch (error3) {
                            log('Method 3 failed:', error3.message);

                            try {
                                // Method 4: Just host
                                await qz.websocket.connect(host);
                                connected = true;
                            } catch (error4) {
                                log('Method 4 failed:', error4.message);

                                try {
                                    // Method 5: No parameters
                                    await qz.websocket.connect();
                                    connected = true;
                                } catch (error5) {
                                    log('Method 5 failed:', error5.message);
                                }
                            }
                        }
                    }
                }

                if (!connected) {
                    throw new Error('All connection methods failed');
                }

                // Wait for connection to stabilize
                await new Promise(resolve => setTimeout(resolve, 500));

                if (qz.websocket.isActive()) {
                    state.connected = true;
                    state.connectionPromise = null;

                    emit('connected', { host, port, secure });
                    log(`✅ Connected to ${host}:${port}`);

                    // Start connection monitor
                    startConnectionMonitor();

                    resolve(true);
                } else {
                    throw new Error('Connection established but not active');
                }

            } catch (error) {
                console.error('Connection error:', error);
                state.connected = false;
                state.connectionPromise = null;

                emit('connection-failed', {
                    error: error.message,
                    host,
                    port,
                    secure
                });

                reject(error);
            }
        });

        return state.connectionPromise;
    }

    // Connection monitor
    function startConnectionMonitor() {
        if (state.connectionMonitor) {
            clearInterval(state.connectionMonitor);
        }

        state.connectionMonitor = setInterval(() => {
            if (!isConnected() && state.connected) {
                log('Connection lost detected');
                state.connected = false;
                emit('disconnected');
                clearInterval(state.connectionMonitor);
                state.connectionMonitor = null;

                // Try to reconnect if autoConnect is enabled
                if (state.settings.autoConnect) {
                    setTimeout(() => {
                        log('Attempting to reconnect...');
                        connect(state.connectionHost, state.connectionPort, state.connectionSecure)
                            .catch(() => {
                                log('Reconnection failed');
                            });
                    }, 3000);
                }
            }
        }, 5000);
    }

    async function disconnect() {
        if (state.connectionMonitor) {
            clearInterval(state.connectionMonitor);
            state.connectionMonitor = null;
        }

        try {
            if (qz.websocket && qz.websocket.isActive()) {
                await qz.websocket.disconnect();
            }
        } catch (error) {
            // Ignore disconnect errors
        }

        state.connected = false;
        state.connectionPromise = null;

        emit('disconnected');
        log('Disconnected from QZ Tray');

        return true;
    }

    // Enhanced printer discovery
    async function findPrinters(search = null, detailed = false) {
        log(`Finding printers${search ? ` matching: ${search}` : ''}...`);
        emit('finding-printers', { search, detailed });

        try {
            // Ensure connection
            if (!isConnected()) {
                const connected = await connect();
                if (!connected) {
                    throw new Error('Could not connect to QZ Tray');
                }
            }

            let printers;
            if (search) {
                printers = await qz.printers.find(search);
            } else {
                printers = await qz.printers.find();
            }

            if (printers && printers.length > 0) {
                // Store printers
                state.printers = printers;

                // Cache results
                if (state.settings.cachePrinters) {
                    localStorage.setItem(CONFIG.STORAGE_KEYS.PRINTERS, JSON.stringify({
                        printers: printers,
                        timestamp: Date.now(),
                    }));
                }

                // Get detailed information if requested
                if (detailed && state.settings.showPrinterDetails) {
                    await getPrinterDetails(printers);
                }

                emit('printers-found', {
                    printers,
                    search,
                    count: printers.length,
                    detailed
                });
                log(`✅ Found ${printers.length} printer(s)`);

                return printers;
            }

            log('No printers found');
            emit('printers-not-found', { search });
            return [];

        } catch (error) {
            console.error('Failed to find printers:', error);
            emit('printers-error', { error: error.message });

            // Try to return cached printers
            try {
                const cached = localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTERS);
                if (cached) {
                    const data = JSON.parse(cached);
                    if (Date.now() - data.timestamp < CONFIG.PRINTER_CACHE_TTL) {
                        log('Using cached printers');
                        return data.printers || [];
                    }
                }
            } catch (e) {
                // Ignore cache errors
            }

            return [];
        }
    }

    // Default printer
    async function findDefaultPrinter(detailed = false) {
        log('Finding default printer...');
        emit('finding-default-printer');

        try {
            if (!isConnected()) {
                const connected = await connect();
                if (!connected) {
                    throw new Error('Could not connect to QZ Tray');
                }
            }

            const defaultPrinter = await qz.printers.getDefault();

            if (defaultPrinter) {
                log(`✅ Default printer: ${defaultPrinter}`);

                // Get detailed information if requested
                if (detailed && state.settings.showPrinterDetails) {
                    const details = await getPrinterDetails([defaultPrinter]);
                    if (details && details.length > 0) {
                        emit('default-printer-found', {
                            printer: defaultPrinter,
                            details: details[0]
                        });
                        return { printer: defaultPrinter, details: details[0] };
                    }
                }

                emit('default-printer-found', { printer: defaultPrinter });
                return defaultPrinter;
            }

            log('No default printer found');
            emit('default-printer-not-found');
            return null;

        } catch (error) {
            console.error('Failed to find default printer:', error);
            emit('default-printer-error', { error: error.message });
            return null;
        }
    }

    async function getPrinterDetails(printers) {
        log('Getting printer details...');
        emit('getting-printer-details', { printers });

        const details = [];

        try {
            for (const printer of printers) {
                const printerName = typeof printer === 'string' ? printer : printer.name;

                if (!state.printerDetails.has(printerName)) {
                    try {
                        const detail = await qz.printers.getDetails(printerName);
                        state.printerDetails.set(printerName, detail);
                        details.push({ name: printerName, details: detail });
                    } catch (error) {
                        details.push({ name: printerName, details: null, error: error.message });
                    }
                } else {
                    details.push({
                        name: printerName,
                        details: state.printerDetails.get(printerName)
                    });
                }
            }

            emit('printer-details-received', { details });
            log(`✅ Got details for ${details.length} printer(s)`);
            return details;

        } catch (error) {
            console.error('Failed to get printer details:', error);
            emit('printer-details-error', { error: error.message });
            return details;
        }
    }

    // Set current printer
    async function setPrinter(printerName, detailed = false) {
        log(`Setting printer to: ${printerName}`);
        emit('setting-printer', { printer: printerName });

        try {
            // Verify printer exists
            const printers = await findPrinters(printerName, true);
            const printer = printers.find(p =>
                (typeof p === 'string' ? p : p.name) === printerName
            );

            if (!printer) {
                throw new Error(`Printer "${printerName}" not found`);
            }

            state.currentPrinter = printerName;

            // Get details if requested
            let details = null;
            if (detailed) {
                const printerDetails = await getPrinterDetails([printerName]);
                details = printerDetails[0];
            }

            // Save to localStorage
            localStorage.setItem('qz_selected_printer', printerName);

            emit('printer-set', {
                printer: printerName,
                details,
                previous: state.currentPrinter
            });

            log(`✅ Printer set to: ${printerName}`);
            return { printer: printerName, details };

        } catch (error) {
            console.error('Failed to set printer:', error);
            emit('printer-set-error', { error: error.message });
            return null;
        }
    }

    // Enhanced print function for QZ v2.2.6
    async function smartPrint(content, options = {}) {
        const jobId = 'job_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const startTime = Date.now();

        // Determine printer
        let printer = options.printer;
        if (!printer) {
            if (state.currentPrinter) {
                printer = state.currentPrinter;
            } else {
                const defaultPrinter = await findDefaultPrinter();
                printer = defaultPrinter || '';
            }
        }

        const job = {
            id: jobId,
            content: content,
            printer: printer,
            options: {
                type: options.type || 'raw',
                copies: parseInt(options.copies) || 1,
                color: options.color || false,
                duplex: options.duplex || false,
                scale: options.scale || 100,
                ...options
            },
            status: 'queued',
            startTime: startTime,
            attempts: 0,
        };

        state.printQueue.push(job);
        emit('job-queued', { job });

        if (!state.isProcessing) {
            processQueue();
        }

        return jobId;
    }

    // Process job
    async function processJob(job) {
        job.attempts++;
        job.status = 'processing';
        emit('job-processing', { job });

        try {
            log(`Printing job ${job.id} to ${job.printer}...`);

            // Ensure connection
            if (!isConnected()) {
                const connected = await connect();
                if (!connected) {
                    throw new Error('Cannot connect to QZ Tray');
                }
            }

            // Verify printer exists
            const printers = await findPrinters(job.printer);
            if (printers.length === 0) {
                throw new Error(`Printer "${job.printer}" not found`);
            }

            // Create config with options
            const config = qz.configs.create(job.printer, {
                copies: job.options.copies,
                colorType: job.options.color ? 'color' : 'monochrome',
                duplex: job.options.duplex ? 'duplex' : 'simplex',
                scaleContent: job.options.scale / 100,
            });

            // Prepare print data based on type
            let printData;
            if (job.options.type === 'zpl' || job.options.type === 'raw') {
                // Raw printing
                printData = [{
                    type: 'raw',
                    data: job.content,
                    options: {
                        language: job.options.type === 'zpl' ? 'ZPL' : 'ESCPOS'
                    }
                }];
            } else if (job.options.type === 'pdf') {
                // PDF printing
                printData = [{
                    type: 'pdf',
                    format: 'file',
                    data: job.content
                }];
            } else {
                // HTML printing
                printData = [{
                    type: 'html',
                    format: 'html',
                    data: job.content
                }];
            }

            // Execute print with timeout
            const printPromise = qz.print(config, printData);
            const timeoutPromise = new Promise((_, reject) => {
                setTimeout(() => reject(new Error('Print timeout after 30s')), CONFIG.PRINT_TIMEOUT);
            });

            await Promise.race([printPromise, timeoutPromise]);

            // Success
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
                await new Promise(resolve => setTimeout(resolve, 1000 * job.attempts));
            } else if (state.settings.fallbackToBrowser) {
                fallbackPrint(job);
            }
        }
    }

    // Process queue
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

    // Status functions
    function getStatus() {
        return {
            connected: isConnected(),
            printers: state.printers.length,
            printerDetails: state.printerDetails.size,
            queueLength: state.printQueue.length,
            currentPrinter: state.currentPrinter,
            connectionHost: state.connectionHost,
            connectionPort: state.connectionPort,
            connectionSecure: state.connectionSecure,
            version: CONFIG.VERSION,
            qzVersion: window.qz ? window.qz.version : 'unknown',
            settings: state.settings,
            lastActivity: state.lastActivity
        };
    }

    // Enhanced test connection function
    async function testConnection() {
        log('Testing connection...', 'info');
        emit('testing-connection');

        const start = Date.now();

        try {
            // Try to connect
            const connected = await connect();

            if (connected) {
                // Test printer discovery
                const printers = await findPrinters();
                const duration = Date.now() - start;

                const result = {
                    success: true,
                    duration,
                    printers: printers.length,
                    qzConnected: isConnected(),
                    version: CONFIG.VERSION,
                    qzVersion: window.qz?.version || 'unknown'
                };

                emit('connection-test-success', result);
                return result;
            } else {
                throw new Error('Could not establish connection');
            }
        } catch (error) {
            const result = {
                success: false,
                error: error.message,
                duration: Date.now() - start,
                qzConnected: false
            };

            emit('connection-test-failed', result);
            return result;
        }
    }

    // Initialize
    async function initialize() {
        try {
            log('Initializing Smart Print Enhanced v' + CONFIG.VERSION + ' for QZ v2.2.6');

            // Wait for QZ Tray library
            await waitForQz();

            // Check if QZ Tray is available
            if (!window.qz) {
                throw new Error('QZ Tray library not loaded. Make sure to include qz-tray.js');
            }

            // Setup security
            setupSecurity();

            // Load saved settings
            const savedSettings = localStorage.getItem(CONFIG.STORAGE_KEYS.SETTINGS);
            if (savedSettings) {
                state.settings = { ...state.settings, ...JSON.parse(savedSettings) };
            }

            // Load saved connection
            const savedConnection = localStorage.getItem(CONFIG.STORAGE_KEYS.CONNECTION);
            if (savedConnection) {
                const connection = JSON.parse(savedConnection);
                state.connectionHost = connection.host || 'localhost';
                state.connectionPort = connection.port || 8181;
                state.connectionSecure = connection.secure || false;
            }

            // Load selected printer
            const savedPrinter = localStorage.getItem('qz_selected_printer');
            if (savedPrinter) {
                state.currentPrinter = savedPrinter;
            }

            // Load cached printers
            const cachedPrinters = localStorage.getItem(CONFIG.STORAGE_KEYS.PRINTERS);
            if (cachedPrinters) {
                try {
                    const data = JSON.parse(cachedPrinters);
                    if (Date.now() - data.timestamp < CONFIG.PRINTER_CACHE_TTL) {
                        state.printers = data.printers || [];
                    }
                } catch (e) {
                    // Ignore cache errors
                }
            }

            emit('ready', {
                version: CONFIG.VERSION,
                qzVersion: window.qz.version,
                settings: state.settings,
                connected: isConnected()
            });

            log('✅ Smart Print Enhanced initialized for QZ v' + window.qz.version);

            // Auto-connect if enabled
            if (state.settings.autoConnect) {
                setTimeout(async () => {
                    try {
                        await connect();

                        if (state.settings.autoDiscoverPrinters) {
                            await findPrinters();
                            await findDefaultPrinter();
                        }
                    } catch (error) {
                        log('Auto-connect failed:', error.message);
                    }
                }, 1000);
            }

        } catch (error) {
            console.error('Initialization failed:', error);
            emit('init-failed', { error: error.message });
        }
    }

    // Public API
    const SmartPrintAPI = {
        // Connection
        connect: connect,
        disconnect: disconnect,
        isConnected: isConnected,
        testConnection: testConnection,

        // Printer discovery
        findPrinters: findPrinters,
        findDefaultPrinter: findDefaultPrinter,
        getPrinterDetails: getPrinterDetails,
        setPrinter: setPrinter,
        getCurrentPrinter: () => state.currentPrinter,

        // Printing
        print: smartPrint,
        printRaw: function(data, options = {}) {
            return smartPrint(data, { ...options, type: 'raw' });
        },
        printZPL: function(zplData, options = {}) {
            return smartPrint(zplData, { ...options, type: 'zpl' });
        },
        printPDF: function(pdfUrl, options = {}) {
            return smartPrint(pdfUrl, { ...options, type: 'pdf' });
        },
        printHTML: function(html, options = {}) {
            return smartPrint(html, { ...options, type: 'html' });
        },

        // Status
        getStatus: getStatus,
        getPrinters: () => state.printers,
        getPrinterDetailsMap: () => new Map(state.printerDetails),

        // Settings
        updateSettings: function(newSettings) {
            state.settings = { ...state.settings, ...newSettings };
            localStorage.setItem(CONFIG.STORAGE_KEYS.SETTINGS, JSON.stringify(state.settings));
            emit('settings-changed', { settings: state.settings });
            return state.settings;
        },
        getSettings: () => ({ ...state.settings }),

        updateConnection: function(host, port = 8181, secure = false) {
            state.connectionHost = host;
            state.connectionPort = port;
            state.connectionSecure = secure;
            localStorage.setItem(CONFIG.STORAGE_KEYS.CONNECTION,
                JSON.stringify({ host, port, secure }));
            emit('connection-changed', { host, port, secure });
        },
        getConnection: () => ({
            host: state.connectionHost,
            port: state.connectionPort,
            secure: state.connectionSecure
        }),

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
                sessionStorage.removeItem(key);
            });

            // Clear all qz-related items
            Object.keys(localStorage).forEach(key => {
                if (key.startsWith('qz_')) {
                    localStorage.removeItem(key);
                }
            });

            Object.keys(sessionStorage).forEach(key => {
                if (key.startsWith('qz_')) {
                    sessionStorage.removeItem(key);
                }
            });

            state.printers = [];
            state.printerDetails.clear();
            state.connected = false;
            state.currentPrinter = null;
            state.printQueue = [];

            emit('cache-cleared');
        },

        version: CONFIG.VERSION,
        getConfig: () => ({ ...CONFIG })
    };

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
