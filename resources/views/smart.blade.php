<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Smart Print — Laravel QZ Tray</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f6f9; color: #333; }
        header { background: #1a1a2e; color: #fff; padding: 16px 32px; display: flex; align-items: center; gap: 12px; }
        header h1 { font-size: 1.3rem; font-weight: 600; }
        header span { font-size: 0.8rem; background: #0f3460; padding: 3px 10px; border-radius: 20px; }
        .container { max-width: 960px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.08); padding: 24px; margin-bottom: 24px; }
        .card h2 { font-size: 1rem; font-weight: 600; margin-bottom: 16px; color: #1a1a2e; border-bottom: 2px solid #e8ecf0; padding-bottom: 10px; }
        .status-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: #ccc; flex-shrink: 0; }
        .dot.connected { background: #22c55e; box-shadow: 0 0 6px #22c55e88; }
        .dot.disconnected { background: #ef4444; }
        .dot.connecting { background: #f59e0b; animation: pulse 1s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.4} }
        label { display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 6px; color: #555; }
        input, select { width: 100%; padding: 9px 12px; border: 1px solid #dde1e7; border-radius: 6px; font-size: 0.9rem; outline: none; transition: border .2s; }
        input:focus, select:focus { border-color: #6366f1; box-shadow: 0 0 0 3px #6366f122; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .btn { padding: 10px 20px; border: none; border-radius: 7px; cursor: pointer; font-size: 0.9rem; font-weight: 500; transition: all .15s; }
        .btn-primary { background: #6366f1; color: #fff; }
        .btn-primary:hover { background: #4f46e5; }
        .btn-success { background: #22c55e; color: #fff; }
        .btn-success:hover { background: #16a34a; }
        .btn-warning { background: #f59e0b; color: #fff; }
        .btn-warning:hover { background: #d97706; }
        .btn-danger  { background: #ef4444; color: #fff; }
        .btn-danger:hover  { background: #dc2626; }
        .btn-sm { padding: 6px 14px; font-size: 0.82rem; }
        .btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        #printer-list { list-style: none; }
        #printer-list li { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-radius: 6px; background: #f8fafc; margin-bottom: 6px; font-size: 0.88rem; }
        #printer-list li.active-printer { background: #eef2ff; border: 1px solid #6366f1; font-weight: 600; }
        #log { background: #0f172a; color: #94a3b8; border-radius: 8px; padding: 16px; font-family: monospace; font-size: 0.8rem; min-height: 120px; max-height: 220px; overflow-y: auto; }
        #log .info  { color: #60a5fa; }
        #log .success { color: #34d399; }
        #log .error { color: #f87171; }
        #log .warn  { color: #fbbf24; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
        .badge-green { background: #dcfce7; color: #16a34a; }
        .badge-red   { background: #fee2e2; color: #dc2626; }
        .badge-gray  { background: #f1f5f9; color: #64748b; }
        .hotkey-tip { font-size: 0.78rem; color: #94a3b8; margin-top: 8px; }
        kbd { background: #e2e8f0; color: #334155; border-radius: 4px; padding: 1px 6px; font-family: monospace; font-size: 0.8rem; }
    </style>
</head>
<body>

<header>
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    <h1>Smart Print</h1>
    <span>Laravel QZ Tray</span>
</header>

<div class="container">

    {{-- Connection Status --}}
    <div class="card">
        <h2>Connection</h2>
        <div class="status-row">
            <div class="dot connecting" id="conn-dot"></div>
            <span id="conn-label">Connecting to QZ Tray...</span>
            <span class="badge badge-gray" id="conn-badge">—</span>
        </div>
        <div class="btn-row">
            <button class="btn btn-success btn-sm" onclick="SmartPrint.connect()">Connect</button>
            <button class="btn btn-warning btn-sm" onclick="SmartPrint.disconnect()">Disconnect</button>
            <button class="btn btn-primary btn-sm" onclick="refreshPrinters()">Refresh Printers</button>
            <button class="btn btn-sm" style="background:#e2e8f0;color:#334155" onclick="SmartPrint.clearCache()">Clear Cache</button>
        </div>
        <p class="hotkey-tip">Tip: Press <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>P</kbd> anywhere to switch printers.</p>
    </div>

    {{-- Printers --}}
    <div class="card">
        <h2>Available Printers</h2>
        <ul id="printer-list"><li style="color:#94a3b8;padding:8px 0;">No printers loaded yet…</li></ul>
        <div style="margin-top:14px;">
            <label>Current Printer</label>
            <input type="text" id="current-printer" placeholder="None selected" readonly
                   style="background:#f8fafc;cursor:default;">
        </div>
    </div>

    {{-- Print a PDF --}}
    <div class="card">
        <h2>Print a PDF</h2>
        <div class="grid-2">
            <div>
                <label>PDF URL</label>
                <input type="text" id="pdf-url" value="{{ route('qz.test.pdf') }}" placeholder="/your/file.pdf">
            </div>
            <div>
                <label>Printer (leave blank for current)</label>
                <input type="text" id="pdf-printer" placeholder="e.g. HP LaserJet">
            </div>
        </div>
        <div class="grid-3" style="margin-top:12px;">
            <div>
                <label>Copies</label>
                <input type="number" id="pdf-copies" value="1" min="1" max="99">
            </div>
            <div>
                <label>Paper Profile</label>
                <select id="pdf-profile">
                    <option value="">Default (A4)</option>
                    <option value="small">Small (80mm thermal)</option>
                    <option value="label">Label (100×150mm)</option>
                </select>
            </div>
            <div>
                <label>Delay (ms)</label>
                <input type="number" id="pdf-delay" value="0" min="0" step="500">
            </div>
        </div>
        <div class="btn-row">
            <button class="btn btn-primary" onclick="doPdfPrint()">🖨 Print PDF</button>
            <button class="btn btn-sm" style="background:#e2e8f0;color:#334155"
                    onclick="window.open(document.getElementById('pdf-url').value,'_blank')">Preview</button>
        </div>
    </div>

    {{-- Raw / ZPL / ESC/POS --}}
    <div class="card">
        <h2>Raw / ZPL / ESC·POS Print</h2>
        <div class="grid-2" style="margin-bottom:12px;">
            <div>
                <label>Type</label>
                <select id="raw-type">
                    <option value="zpl">ZPL (Zebra)</option>
                    <option value="escpos">ESC/POS (Thermal)</option>
                    <option value="raw">Raw</option>
                </select>
            </div>
            <div>
                <label>Printer</label>
                <input type="text" id="raw-printer" placeholder="e.g. Zebra ZD420">
            </div>
        </div>
        <div>
            <label>Data / Commands</label>
            <textarea id="raw-data" rows="5"
                      style="width:100%;padding:9px 12px;border:1px solid #dde1e7;border-radius:6px;font-family:monospace;font-size:0.82rem;resize:vertical;"
                      placeholder="^XA&#10;^FO50,50^ADN,36,20^FDHello World^FS&#10;^XZ"></textarea>
        </div>
        <div class="btn-row">
            <button class="btn btn-primary" onclick="doRawPrint()">🖨 Print Raw</button>
            <button class="btn btn-sm" style="background:#e2e8f0;color:#334155"
                    onclick="loadZplSample()">Load ZPL Sample</button>
            <button class="btn btn-sm" style="background:#e2e8f0;color:#334155"
                    onclick="loadEscposSample()">Load ESC/POS Sample</button>
        </div>
    </div>

    {{-- Data-Attribute Demo --}}
    <div class="card">
        <h2>Data-Attribute Buttons (HTML Usage)</h2>
        <p style="font-size:.85rem;color:#64748b;margin-bottom:14px;">
            These buttons demonstrate the <code>data-qz-print</code> attribute — no JavaScript needed.
        </p>
        <div class="btn-row">
            <button class="btn btn-success"
                    data-qz-print="{{ route('qz.test.pdf') }}"
                    data-qz-type="pdf">
                Print Test PDF (auto printer)
            </button>
            <button class="btn btn-warning"
                    data-qz-print="{{ route('qz.test.pdf') }}"
                    data-qz-copies="2"
                    data-qz-type="pdf">
                Print 2 Copies
            </button>
            <button class="btn btn-primary"
                    data-qz-print="{{ route('qz.test.pdf') }}"
                    data-qz-delay="1500"
                    data-qz-type="pdf">
                Print with 1.5 s delay
            </button>
        </div>
    </div>

    {{-- Event Log --}}
    <div class="card">
        <h2>Event Log</h2>
        <div id="log"><span class="info">Waiting for events…</span></div>
        <div class="btn-row">
            <button class="btn btn-sm btn-danger" onclick="document.getElementById('log').innerHTML=''">Clear</button>
        </div>
    </div>

</div>

{{-- QZ Tray library (CDN) --}}
<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js"></script>
{{-- Laravel QZ Tray smart-print library --}}
<script src="{{ asset('vendor/qz-tray/js/smart-print.js') }}"></script>

<script>
// ── Logger ───────────────────────────────────────────────
function log(msg, level) {
    var el = document.getElementById('log');
    var line = document.createElement('div');
    line.className = level || 'info';
    line.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
    el.appendChild(line);
    el.scrollTop = el.scrollHeight;
}

// ── Connection state UI ──────────────────────────────────
function setConnState(state) {
    var dot   = document.getElementById('conn-dot');
    var label = document.getElementById('conn-label');
    var badge = document.getElementById('conn-badge');
    dot.className = 'dot ' + state;
    if (state === 'connected') {
        label.textContent = 'Connected to QZ Tray';
        badge.textContent = '✓ Ready';
        badge.className = 'badge badge-green';
    } else if (state === 'disconnected') {
        label.textContent = 'Disconnected';
        badge.textContent = '✗ Offline';
        badge.className = 'badge badge-red';
    } else {
        label.textContent = 'Connecting…';
        badge.textContent = '…';
        badge.className = 'badge badge-gray';
    }
}

// ── Printer list UI ──────────────────────────────────────
// Escape HTML special characters to prevent XSS when printer names
// contain <, >, &, ", or '. Printer names come from the OS and could
// theoretically be crafted.
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderPrinters(printers) {
    var ul  = document.getElementById('printer-list');
    var cur = SmartPrint.getCurrentPrinter();
    if (!printers || printers.length === 0) {
        ul.innerHTML = '<li style="color:#94a3b8;">No printers found.</li>';
        return;
    }
    ul.innerHTML = '';
    printers.forEach(function(p) {
        var li = document.createElement('li');
        if (p === cur) li.classList.add('active-printer');
        // Build the Use button via DOM API so the printer name is safely
        // passed through the data attribute instead of string-interpolated
        // into an onclick handler (which would be an XSS sink).
        var span = document.createElement('span');
        span.textContent = p; // textContent is safe — no HTML parsing
        li.appendChild(span);
        var btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-primary';
        btn.style.cssText = 'padding:4px 10px;font-size:.78rem;';
        btn.textContent = 'Use';
        btn.dataset.printer = p;
        btn.addEventListener('click', function() {
            SmartPrint.setPrinter(this.dataset.printer);
            updateCurrentPrinterInput();
        });
        li.appendChild(btn);
        ul.appendChild(li);
    });
}

function updateCurrentPrinterInput() {
    var cur = SmartPrint.getCurrentPrinter();
    document.getElementById('current-printer').value = cur || '';
    // re-render to highlight active
    SmartPrint.getPrinters().then(renderPrinters);
}

async function refreshPrinters() {
    log('Refreshing printer list…');
    var printers = await SmartPrint.getPrinters();
    renderPrinters(printers);
    log('Found ' + printers.length + ' printer(s)', 'success');
}

// ── Print actions ────────────────────────────────────────
function doPdfPrint() {
    var url     = document.getElementById('pdf-url').value.trim();
    var printer = document.getElementById('pdf-printer').value.trim() || undefined;
    var copies  = parseInt(document.getElementById('pdf-copies').value) || 1;
    var profile = document.getElementById('pdf-profile').value || undefined;
    var delay   = parseInt(document.getElementById('pdf-delay').value) || 0;

    if (!url) { log('Please enter a PDF URL', 'error'); return; }

    log('Queuing PDF print: ' + url);
    var job = { url: url, type: 'pdf', printer: printer, copies: copies, profile: profile };
    if (delay > 0) {
        setTimeout(function() { SmartPrint.print(job); }, delay);
    } else {
        SmartPrint.print(job);
    }
}

function doRawPrint() {
    var data    = document.getElementById('raw-data').value;
    var type    = document.getElementById('raw-type').value;
    var printer = document.getElementById('raw-printer').value.trim() || undefined;

    if (!data.trim()) { log('Please enter raw data', 'error'); return; }
    log('Queuing ' + type.toUpperCase() + ' print…');
    SmartPrint.printRaw(data, type, printer);
}

function loadZplSample() {
    document.getElementById('raw-type').value = 'zpl';
    document.getElementById('raw-data').value =
        '^XA\n^FO50,50^ADN,36,20^FDLaravel QZ Tray^FS\n^FO50,100^ADN,24,14^FDZebra Label Test^FS\n^XZ';
}

function loadEscposSample() {
    document.getElementById('raw-type').value = 'escpos';
    document.getElementById('raw-data').value =
        '\x1B\x40' +           // Init
        '\x1B\x21\x08' +       // Bold
        'Laravel QZ Tray\n' +
        '\x1B\x21\x00' +       // Normal
        'ESC/POS Test Receipt\n' +
        '------------------------\n' +
        'Item 1          $10.00\n' +
        'Item 2           $5.50\n' +
        '------------------------\n' +
        'Total           $15.50\n\n\n';
}

// ── SmartPrint events ────────────────────────────────────
SmartPrint.on('connected', function(d) {
    setConnState('connected');
    log('Connected! Printers: ' + (d.printers || []).join(', '), 'success');
    renderPrinters(d.printers);
    updateCurrentPrinterInput();
});

SmartPrint.on('disconnected', function() {
    setConnState('disconnected');
    log('Disconnected from QZ Tray', 'warn');
});

SmartPrint.on('connection-failed', function() {
    setConnState('disconnected');
    log('Could not connect — is QZ Tray installed and running?', 'error');
});

SmartPrint.on('printers-loaded', function(d) {
    renderPrinters(d.printers);
});

SmartPrint.on('job-queued', function(d) {
    log('Job queued: ' + (d.job.type || '?') + ' → ' + (d.job.url || 'raw data'));
});

SmartPrint.on('job-completed', function(d) {
    log('✓ Job completed: ' + (d.job.url || 'raw data'), 'success');
});

SmartPrint.on('job-failed', function(d) {
    log('✗ Job failed: ' + (d.error || 'unknown error'), 'error');
});

SmartPrint.on('fallback-print', function() {
    log('Falling back to browser print dialog', 'warn');
});

SmartPrint.on('cache-cleared', function() {
    log('Cache cleared', 'info');
});

SmartPrint.on('printer-saved', function(d) {
    log('Printer saved: ' + d.printer + ' (' + d.scope + ')');
    updateCurrentPrinterInput();
});

SmartPrint.on('ready', function() {
    log('SmartPrint ready');
    if (!SmartPrint.isConnected()) {
        setConnState('disconnected');
    }
});
</script>
</body>
</html>
