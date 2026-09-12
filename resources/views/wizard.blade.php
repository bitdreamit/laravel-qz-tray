<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QZ Tray Client Setup Wizard</title>
<style>
    :root { --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --brand:#0f766e; --brand-soft:#f0fdfa; --ok:#16a34a; --warn:#d97706; --bad:#dc2626; }
    * { box-sizing:border-box; }
    body { margin:0; font:15px/1.6 -apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:var(--ink); background:#f8fafc; }
    .wrap { max-width:880px; margin:0 auto; padding:32px 20px 64px; }
    h1 { font-size:26px; margin:0 0 4px; }
    p.lead { color:var(--muted); margin:0 0 28px; }
    .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:20px 22px; margin-bottom:16px; }
    .card h2 { font-size:17px; margin:0 0 10px; display:flex; align-items:center; gap:10px; }
    .badge { font-size:11px; font-weight:600; letter-spacing:.4px; padding:3px 9px; border-radius:999px; background:var(--brand-soft); color:var(--brand); border:1px solid #99f6e4; }
    .badge.warn { background:#fffbeb; color:var(--warn); border-color:#fde68a; }
    .badge.bad { background:#fef2f2; color:var(--bad); border-color:#fecaca; }
    .badge.ok { background:#f0fdf4; color:var(--ok); border-color:#bbf7d0; }
    code, pre { font:13px/1.55 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
    pre { background:#0f172a; color:#e2e8f0; padding:12px 14px; border-radius:8px; overflow:auto; margin:8px 0 0; white-space:pre-wrap; word-break:break-word; }
    pre.inline { background:#f1f5f9; color:var(--ink); padding:2px 6px; display:inline; border-radius:5px; }
    .row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px; }
    .btn { display:inline-block; padding:9px 16px; border-radius:8px; border:1px solid var(--brand); background:var(--brand); color:#fff; font-weight:600; font-size:14px; cursor:pointer; text-decoration:none; }
    .btn.ghost { background:#fff; color:var(--brand); }
    .btn:disabled { opacity:.5; cursor:not-allowed; }
    .muted { color:var(--muted); font-size:13.5px; }
    ol.steps { margin:8px 0 0; padding-left:20px; }
    ol.steps li { margin-bottom:6px; }
    .kp { border:1px dashed var(--brand); background:var(--brand-soft); border-radius:8px; padding:10px 14px; margin-top:10px; font:14px/1.6 ui-monospace,Menlo,Consolas,monospace; word-break:break-all; }
    .result { margin-top:12px; padding:12px 14px; border-radius:8px; font-size:14px; display:none; }
    .result.ok { display:block; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
    .result.err { display:block; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
    .result.wait { display:block; background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
    table { width:100%; border-collapse:collapse; font-size:13.5px; margin-top:8px; }
    td { padding:6px 8px; border-top:1px solid var(--line); vertical-align:top; }
    td:first-child { color:var(--muted); width:38%; }
</style>
</head>
<body>
<div class="wrap">
    <h1>QZ Tray Client Setup Wizard</h1>
    <p class="lead">Make printing on this machine 100% prompt-free — transport TLS, signing trust and browser local-network access, all free.</p>

    {{-- Server posture ------------------------------------------------ --}}
    <div class="card">
        <h2>1 · Server signing certificate
            @if($fingerprint) <span class="badge ok">{{ $mode === 'public-ca' ? 'PUBLIC CA — already prompt-free' : ($mode === 'own-ca' ? 'OWN CA — deploy override.crt' : 'SELF-SIGNED — trust once per machine') }}</span>
            @else <span class="badge bad">MISSING</span> @endif
        </h2>
        @if($fingerprint)
            <p class="muted">Subject: <b>{{ $subjectCn }}</b> — serves every tenant subdomain (identical fingerprint = clients trust once for ALL tenants).
            @if($daysLeft !== null) Valid for <b>{{ $daysLeft }}</b> more days.@endif</p>
            <div class="kp" id="fingerprint">{{ $fingerprint }}</div>
            <p class="muted" style="margin-top:6px">SHA-1 fingerprint — must match the value shown by QZ Tray's dialog / Site Manager. QZ Tray shows exactly this format.</p>
            <div class="row">
                <a class="btn ghost" href="{{ $certUrl }}" target="_blank">View site certificate</a>
                <a class="btn ghost" href="{{ $caCertUrl }}">Download trust root (override.crt)</a>
                <button class="btn ghost" type="button" onclick="copyText(document.getElementById('fingerprint').textContent.trim(), this)">Copy fingerprint</button>
            </div>
        @else
            <p class="muted">No certificate found on the server. Run on the server:<br>
            <code>php artisan qz:generate-ca &amp;&amp; php artisan qz:generate-certificate --ca --domain=*.yourdomain.com,yourdomain.com</code></p>
        @endif
    </div>

    {{-- Client bundle ------------------------------------------------- --}}
    <div class="card">
        <h2>2 · One-shot Windows trust bundle <span class="badge">RECOMMENDED</span></h2>
        <p class="muted">Download on the print client PC, right-click <b>setup.bat</b> → <b>Run as administrator</b>. It silently installs every trust this machine needs:</p>
        <ol class="steps">
            <li>QZ Tray's localhost root → Windows Root store <i>(no browser TLS warning on wss://localhost:8181)</i></li>
            <li>override.crt → QZ Tray folder <i>(no "Untrusted website" dialog, ever)</i></li>
            <li>Site certificate → allowed.dat <i>(belt &amp; suspenders for older versions)</i></li>
            <li>Chrome/Edge LocalNetworkAccess policy for your domains <i>(no "access local network" prompt)</i></li>
        </ol>
        <div class="row">
            <a class="btn" href="{{ $bundleUrl }}">Download qz-client-bundle.zip</a>
        </div>
        <p class="muted" style="margin-top:10px">Fleet deployment: push <code>qz-client-setup.ps1</code> via GPO / Intune startup script. Idempotent — safe to re-run. QZ Tray 2.2.4+ alternative: sideload the included <code>provision.json</code>.</p>
    </div>

    {{-- Install / probe QZ Tray --------------------------------------- --}}
    <div class="card">
        <h2>3 · QZ Tray on this PC <span class="badge" id="tray-badge">NOT CHECKED</span></h2>
        <p class="muted">Not installed yet? Get it from <a href="https://qz.io/download" target="_blank" rel="noopener">qz.io/download</a>@if($installerUrl) or <a href="{{ $installerUrl }}">your local copy</a>@endif, then test the connection:</p>
        <div class="row">
            <button class="btn" type="button" id="probe-btn" onclick="probeTray()">Test connection to QZ Tray</button>
        </div>
        <div class="result" id="probe-result"></div>
    </div>

    {{-- Zero-click path ----------------------------------------------- --}}
    <div class="card">
        <h2>4 · Prefer zero client-side files? <span class="badge">Alternative</span></h2>
        <p class="muted">A <b>real CA certificate</b> (Let's Encrypt, cPanel AutoSSL) as the signing certificate is trusted by QZ Tray <b>silently — no client deployment at all</b>. Import it and keep it fresh:</p>
        <pre>php artisan qz:certificate:import --cert=/etc/letsencrypt/live/app.example.com/fullchain.pem --key=.../privkey.pem
php artisan qz:watch-certificate        # auto re-imports after each renewal (schedule it)</pre>
        <p class="muted" style="margin-top:8px">With admin-controlled Windows clients the own-CA bundle (step 2) is equally prompt-free and needs no renewal coordination. Full comparison: <b>docs/zero-prompt.md</b>.</p>
    </div>

    {{-- Manual fallback ----------------------------------------------- --}}
    <div class="card">
        <h2>5 · No admin rights? Manual one-click fallback</h2>
        <p class="muted">When a dialog appears, choose <b>"Always Allow" / "Remember this decision"</b> — not plain "Allow". Plain Allow forgets the decision, which is why prompts kept coming back. One remembered decision covers <b>every tenant subdomain</b> sharing this certificate.</p>
    </div>
</div>

<script>
function copyText(t, btn) {
    navigator.clipboard.writeText(t).then(function () {
        if (btn) { var o = btn.textContent; btn.textContent = 'Copied ✓'; setTimeout(function(){ btn.textContent = o; }, 1500); }
    });
}

function probeTray() {
    var badge = document.getElementById('tray-badge');
    var box = document.getElementById('probe-result');
    var btn = document.getElementById('probe-btn');
    btn.disabled = true;
    badge.textContent = 'CHECKING…'; badge.className = 'badge';
    box.className = 'result wait';
    box.textContent = 'Opening secure WebSocket to wss://localhost:8181 …';

    if (!window.qz || !qz.websocket) {
        badge.textContent = 'JS MISSING'; badge.className = 'badge bad';
        box.className = 'result err';
        box.textContent = 'qz-tray.js not loaded — publish package assets (php artisan vendor:publish --tag=qz-assets) and include the script.';
        btn.disabled = false;
        return;
    }

    var finished = false;
    function done(ok, msg) {
        if (finished) return;
        finished = true;
        btn.disabled = false;
        if (ok) {
            badge.textContent = 'CONNECTED'; badge.className = 'badge ok';
            box.className = 'result ok';
            box.textContent = msg + ' QZ Tray is reachable. If a dialog appeared anyway, deploy the step-2 bundle to silence it for good.';
        } else {
            badge.textContent = 'NOT REACHABLE'; badge.className = 'badge bad';
            box.className = 'result err';
            box.textContent = msg;
        }
    }

    try {
        qz.websocket.connect({ retries: 1, timeout: 8 }).then(function () {
            var v = '';
            try { v = ' Version: ' + qz.api.getVersion(); } catch (e) {}
            done(true, 'Connection established.' + v);
            setTimeout(function () { try { qz.websocket.disconnect(); } catch (e) {} }, 800);
        }).catch(function (err) {
            done(false, 'Could not connect: ' + (err && err.message ? err.message : err) +
                ' — Is QZ Tray installed and running? Is the printer PC reachable? A TLS warning here means step 2 of the bundle has not run on this PC yet.');
        });
    } catch (e) {
        done(false, 'Probe failed to start: ' + e.message);
    }
}
</script>
<script src="{{ asset('vendor/qz-tray/js/qz-tray.js') }}"></script>
</body>
</html>
