<!-- resources/views/qz-test.blade.php -->
<!DOCTYPE html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>QZ Tray Test</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
        .connected { background: #d4edda; color: #155724; }
        .disconnected { background: #f8d7da; color: #721c24; }
        button { padding: 10px 15px; margin: 5px; }
    </style>
    <script>
        window.QZ_CONFIG = {
            endpoint: '/qz',
            debug: true
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js"></script>
    <script src="{{ asset('vendor/qz-tray/smart-print.js') }}"></script>
</head>
<body>
<h1>QZ Tray Connection Test</h1>

<div id="status" class="status disconnected">
    🔴 Disconnected from QZ Tray
</div>

<div>
    <button onclick="connectQz()">🔗 Connect to QZ Tray</button>
    <button onclick="getPrinters()">🖨️ Get Printers</button>
    <button onclick="testPrint()">🖨️ Test Print</button>
    <button onclick="clearCache()">🗑️ Clear Cache</button>
</div>

<div id="output" style="margin-top:20px; padding:10px; background:#f5f5f5;"></div>

<script>
    function log(msg) {
        const output = document.getElementById('output');
        output.innerHTML += `<div>${new Date().toLocaleTimeString()}: ${msg}</div>`;
        output.scrollTop = output.scrollHeight;
    }

    function updateStatus(connected) {
        const status = document.getElementById('status');
        if (connected) {
            status.className = 'status connected';
            status.innerHTML = '🟢 Connected to QZ Tray';
        } else {
            status.className = 'status disconnected';
            status.innerHTML = '🔴 Disconnected from QZ Tray';
        }
    }

    async function connectQz() {
        log('Connecting to QZ Tray...');
        const connected = await SmartPrint.connect();
        updateStatus(connected);
        log(connected ? '✅ Connected successfully!' : '❌ Connection failed');
    }

    async function getPrinters() {
        log('Fetching printers...');
        try {
            const printers = await SmartPrint.getPrinters();
            log(`Found ${printers.length} printer(s):`);
            printers.forEach(p => log(` - ${typeof p === 'string' ? p : p.name}`));
        } catch (e) {
            log(`Error: ${e.message}`);
        }
    }

    async function testPrint() {
        log('Testing print...');
        try {
            const printers = await SmartPrint.getPrinters();
            if (printers.length === 0) {
                log('❌ No printers found');
                return;
            }

            // Create a simple test PDF
            const testWindow = window.open('', '_blank');
            testWindow.document.write(`
                    <html>
                    <body>
                        <h1>QZ Tray Test Document</h1>
                        <p>Generated: ${new Date().toLocaleString()}</p>
                        <p>If you can see this, printing works!</p>
                    </body>
                    </html>
                `);
            testWindow.document.close();

            const printData = `
                    data:text/html,<html>
                    <body style="font-family:Arial;padding:20px;">
                        <h1>QZ Tray Test</h1>
                        <p>Time: ${new Date().toLocaleString()}</p>
                        <p>✅ Test successful!</p>
                    </body>
                    </html>
                `;

            const jobId = await smartPrint(printData, {
                printer: printers[0]
            });
            log(`✅ Print job started: ${jobId}`);
        } catch (e) {
            log(`❌ Print error: ${e.message}`);
        }
    }

    function clearCache() {
        SmartPrint.clearCache();
        localStorage.clear();
        log('✅ Cache cleared');
    }

    // Auto-connect
    document.addEventListener('DOMContentLoaded', async () => {
        log('Page loaded, initializing...');

        // Listen for connection events
        SmartPrint.on('connected', () => {
            updateStatus(true);
            log('Event: Connected to QZ Tray');
        });

        SmartPrint.on('disconnected', () => {
            updateStatus(false);
            log('Event: Disconnected from QZ Tray');
        });

        SmartPrint.on('job-completed', (data) => {
            log(`Event: Print job completed: ${data.job.id}`);
        });

        SmartPrint.on('job-failed', (data) => {
            log(`Event: Print job failed: ${data.error}`);
        });
    });
</script>
</body>
</html>
