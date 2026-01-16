<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>QZ Tray Test - Working</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        button { padding: 10px 15px; margin: 5px; font-size: 16px; }
        .status { padding: 10px; border-radius: 5px; margin: 10px 0; }
        .connected { background: #d4edda; color: #155724; }
        .disconnected { background: #f8d7da; color: #721c24; }
        .log { background: #f8f9fa; padding: 10px; border-radius: 5px; margin-top: 20px; max-height: 300px; overflow-y: auto; }
    </style>

    <!-- Configuration FIRST -->
    <script>
        window.QZ_CONFIG = {
            endpoint: '/qz',
            debug: true,
            autoConnect: false
        };
    </script>

    <!-- QZ Tray library -->
    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js"></script>

    <!-- Fixed Smart Print -->
    <script src="{{asset('vendor/qz-tray/smart-print.js')}}"></script>
</head>
<body>
<h1>✅ QZ Tray Connection Test</h1>

<div id="status" class="status disconnected">
    🔴 Status: Disconnected
</div>

<div>
    <button onclick="connectQz()">🔗 Connect to QZ Tray</button>
    <button onclick="testConnection()">🔄 Test Connection</button>
    <button onclick="getPrinters()">🖨️ Get Printers</button>
    <button onclick="testPrint()">📄 Test Print</button>
    <button onclick="clearCache()">🗑️ Clear Cache</button>
</div>

<div id="log" class="log"></div>

<script>
    function log(msg, type = 'info') {
        const logDiv = document.getElementById('log');
        const time = new Date().toLocaleTimeString();
        const color = type === 'error' ? 'red' : type === 'success' ? 'green' : 'black';
        logDiv.innerHTML += `<div style="color:${color}">${time}: ${msg}</div>`;
        logDiv.scrollTop = logDiv.scrollHeight;
    }

    function updateStatus(connected) {
        const status = document.getElementById('status');
        if (connected) {
            status.className = 'status connected';
            status.innerHTML = '🟢 Status: Connected to QZ Tray';
        } else {
            status.className = 'status disconnected';
            status.innerHTML = '🔴 Status: Disconnected';
        }
    }

    async function connectQz() {
        log('Connecting to QZ Tray...');
        try {
            const connected = await SmartPrint.connect();
            updateStatus(connected);
            log(connected ? '✅ Connected successfully!' : '❌ Connection failed');
            return connected;
        } catch (err) {
            log(`❌ Error: ${err.message}`, 'error');
            return false;
        }
    }

    async function testConnection() {
        log('Testing connection...');
        const status = SmartPrint.getStatus();
        log(`Connection: ${status.connected ? '✅ Connected' : '❌ Disconnected'}`);
        log(`Printers cached: ${status.printers}`);
        log(`Queue length: ${status.queueLength}`);

        // Try to connect if not connected
        if (!status.connected) {
            return await connectQz();
        }
        return true;
    }

    async function getPrinters() {
        log('Getting printers...');
        try {
            const printers = await SmartPrint.getPrinters();
            if (printers.length === 0) {
                log('❌ No printers found');
            } else {
                log(`✅ Found ${printers.length} printer(s):`);
                printers.forEach(p => {
                    const name = typeof p === 'string' ? p : (p.name || 'Unknown');
                    log(`   - ${name}`);
                });

                // Auto-select first printer
                const firstPrinter = printers[0];
                const printerName = typeof firstPrinter === 'string' ? firstPrinter : firstPrinter.name;
                await SmartPrint.setPrinter(printerName);
                log(`✅ Set default printer to: ${printerName}`);
            }
        } catch (err) {
            log(`❌ Error getting printers: ${err.message}`, 'error');
        }
    }

    async function testPrint() {
        log('Testing print...');

        // First check connection
        if (!SmartPrint.isConnected()) {
            log('Not connected. Connecting first...');
            const connected = await connectQz();
            if (!connected) {
                log('❌ Cannot connect to QZ Tray', 'error');
                return;
            }
        }

        // Get printers
        const printers = await SmartPrint.getPrinters();
        if (printers.length === 0) {
            log('❌ No printers available', 'error');
            return;
        }

        // Create a simple test document
        const testHtml = `
            <html>
            <body style="font-family:Arial;padding:20px;">
                <h1>✅ QZ Tray Test</h1>
                <p>This is a test document printed from QZ Tray.</p>
                <p>Time: ${new Date().toLocaleString()}</p>
                <p>If you can see this, QZ Tray is working correctly!</p>
            </body>
            </html>
        `;

        // Convert to data URL
        const testUrl = 'data:text/html,' + encodeURIComponent(testHtml);

        try {
            const printerName = typeof printers[0] === 'string' ? printers[0] : printers[0].name;
            log(`Printing to: ${printerName}`);

            const jobId = await smartPrint(testUrl, {
                printer: printerName,
                copies: 1
            });

            log(`✅ Print job started: ${jobId}`, 'success');

        } catch (err) {
            log(`❌ Print error: ${err.message}`, 'error');
        }
    }

    function clearCache() {
        SmartPrint.clearCache();
        localStorage.clear();
        log('✅ Cache cleared');
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        log('Page loaded. Smart Print initialized.');

        // Listen for events
        SmartPrint.on('connected', () => {
            updateStatus(true);
            log('Event: Connected to QZ Tray', 'success');
        });

        SmartPrint.on('disconnected', () => {
            updateStatus(false);
            log('Event: Disconnected from QZ Tray');
        });

        SmartPrint.on('job-completed', (data) => {
            log(`Event: Print job completed: ${data.job.id}`, 'success');
        });

        SmartPrint.on('job-failed', (data) => {
            log(`Event: Print job failed: ${data.error}`, 'error');
        });

        // Auto-test after 1 second
        setTimeout(() => {
            testConnection();
        }, 1000);
    });
</script>
</body>
</html>
