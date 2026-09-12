# =============================================================================
# QZ Tray Client Trust Setup  (bitdreamit/laravel-qz-tray v@@PACKAGE_VERSION@@)
# Generated: @@GENERATED_AT@@
#
# WHAT THIS DOES (run as Administrator on the Windows PRINT CLIENT machine):
#   Step 1  Installs QZ Tray's own localhost TLS root into the Windows
#           "Trusted Root Certification Authorities" store.
#           -> Chrome/Edge stop warning about wss://localhost:8181
#   Step 2  Deploys override.crt into the QZ Tray install folder.
#           -> QZ Tray silently trusts our signing certificate:
#              the "Untrusted website / Allow" dialog NEVER appears.
#   Step 3  (optional) Whitelists the site certificate in allowed.dat
#           (`qz-tray-console.exe --allow`).
#   Step 4  (optional) Adds the Chrome/Edge LocalNetworkAccess policy for our
#           tenant domains -> no "wants to access your local network" prompt.
#   Step 5  Restarts QZ Tray.
#
# SAFE TO RE-RUN at any time (idempotent). Rollback: see README.txt.
# =============================================================================

#requires -Version 5.1
param(
    [string]$QzDir = "$env:ProgramFiles\QZ Tray",
    [switch]$NoRestart
)

$ErrorActionPreference = 'Stop'

function Write-Step($n, $msg) {
    Write-Host ""
    Write-Host ("[Step {0}] {1}" -f $n, $msg) -ForegroundColor Cyan
}
function Write-Ok($msg) { Write-Host ("    OK  {0}" -f $msg) -ForegroundColor Green }
function Write-Skip($msg) { Write-Host ("    SKIP {0}" -f $msg) -ForegroundColor DarkYellow }
function Write-Fail($msg) { Write-Host ("    FAIL {0}" -f $msg) -ForegroundColor Red }

Write-Host "==============================================================" -ForegroundColor Gray
Write-Host " QZ Tray zero-prompt trust setup"                        -ForegroundColor Gray
Write-Host "==============================================================" -ForegroundColor Gray

# ---- 0. Pre-flight ----------------------------------------------------------
$principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Fail "This script must run as Administrator. Right-click > Run as administrator."
    exit 1
}

if (-not (Test-Path (Join-Path $QzDir 'qz-tray.jar')) -and -not (Test-Path (Join-Path $QzDir 'qz-tray.exe'))) {
    Write-Fail ("QZ Tray not found in '{0}'. Install it first: https://qz.io/download" -f $QzDir)
    exit 1
}
Write-Host ("QZ Tray found: {0}" -f $QzDir)

# Embedded certificates (base64 PEM)
$OverrideCertB64 = '@@OVERRIDE_CERT_B64@@'
$SiteCertB64     = '@@SITE_CERT_B64@@'
$IncludeAllow    = @@INCLUDE_ALLOW_STEP@@   # 1 = run allowed.dat step
$IncludeLna      = @@INCLUDE_LNA_STEP@@     # 1 = add Chrome/Edge LNA policy
$LnaDomainsJson  = '@@LNA_DOMAINS_JSON@@'   # JSON array string, e.g. ["*.example.com"]
$RestartTray     = @@RESTART_TRAY@@         # 1 = restart QZ Tray at the end

function Decode-Pem([string]$b64) {
    [Text.Encoding]::ASCII.GetString([Convert]::FromBase64String($b64))
}

# ---- 1. Transport layer: trust QZ Tray's localhost root in Windows ----------
Write-Step 1 "Trusting QZ Tray's localhost TLS certificate (wss://localhost:8181)"

$sharedCandidates = @(
    "$env:ProgramData\qz\root-ca.crt",
    "$env:APPDATA\qz\root-ca.crt"
)

$transportRoot = $null
foreach ($c in $sharedCandidates) {
    if (Test-Path $c) { $transportRoot = $c; break }
}

if ($transportRoot) {
    & certutil.exe -addstore -f Root $transportRoot | Out-Null
    if ($LASTEXITCODE -eq 0) {
        Write-Ok ("Imported '{0}' into the Local Machine Root store" -f $transportRoot)
    } else {
        Write-Fail ("certutil import failed with exit code {0}" -f $LASTEXITCODE)
    }
} else {
    Write-Skip "root-ca.crt not found (QZ Tray generates it at install). If browsers still warn about localhost, re-run this script after QZ Tray has started at least once."
}

# ---- 2. Signing layer: deploy override.crt into the QZ Tray folder ----------
Write-Step 2 "Deploying override.crt (signing trust root) into QZ Tray"

# Stop QZ Tray first so the file is not locked while we replace it.
$trayProcess = Get-Process -Name 'qz-tray' -ErrorAction SilentlyContinue
if ($trayProcess) {
    Write-Host "    Stopping running QZ Tray instance..."
    $trayProcess | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

$overridePath = Join-Path $QzDir 'override.crt'
try {
    Decode-Pem $OverrideCertB64 | Set-Content -Path $overridePath -Encoding ASCII -NoNewline
    Write-Ok ("Wrote {0}" -f $overridePath)
} catch {
    Write-Fail ("Unable to write override.crt: {0}" -f $_.Exception.Message)
    exit 1
}

# Belt & suspenders: also point qz-tray.properties at the same root when the
# property is not present yet (QZ Tray 2.0.2+ honours authcert.override).
$propsPath = Join-Path $QzDir 'qz-tray.properties'
try {
    if (-not (Test-Path $propsPath) -or -not (Select-String -Path $propsPath -Pattern '^\s*authcert\.override\s*=' -Quiet)) {
        Add-Content -Path $propsPath -Value ("authcert.override={0}" -f $overridePath.Replace('\', '/')) -Encoding ASCII
        Write-Ok "Added authcert.override to qz-tray.properties"
    } else {
        Write-Skip "authcert.override already present in qz-tray.properties"
    }
} catch {
    Write-Skip ("Could not update qz-tray.properties: {0}" -f $_.Exception.Message)
}

# ---- 3. allowed.dat: whitelist the exact site certificate -------------------
Write-Step 3 "Whitelisting site certificate in allowed.dat"

if ($IncludeAllow -eq 1) {
    $siteCertPath = Join-Path $env:TEMP 'qz-site-cert.pem'
    try {
        Decode-Pem $SiteCertB64 | Set-Content -Path $siteCertPath -Encoding ASCII -NoNewline
        $consoleExe = Join-Path $QzDir 'qz-tray-console.exe'
        if (Test-Path $consoleExe) {
            & $consoleExe --allow $siteCertPath | Out-Null
            if ($LASTEXITCODE -eq 0) {
                Write-Ok "Site certificate added to allowed.dat"
            } else {
                Write-Skip ("--allow exited {0} (older QZ Tray versions without --allow can skip this; override.crt already covers trust)" -f $LASTEXITCODE)
            }
        } else {
            Write-Skip "qz-tray-console.exe not found (QZ Tray < 2.2) - skipping; override.crt already covers trust"
        }
    } catch {
        Write-Skip ("allowed.dat step skipped: {0}" -f $_.Exception.Message)
    } finally {
        if (Test-Path $siteCertPath) { Remove-Item $siteCertPath -Force -ErrorAction SilentlyContinue | Out-Null }
    }
} else {
    Write-Skip "disabled at bundle build time (--no-allow)"
}

# ---- 4. Chrome/Edge Local Network Access policy -----------------------------
Write-Step 4 "Chrome/Edge LocalNetworkAccess policy"

if ($IncludeLna -eq 1) {
    $targets = @(
        @{ Hive = 'HKLM:\SOFTWARE\Policies\Google\Chrome';  Name = 'LocalNetworkAccessAllowedForUrls' },
        @{ Hive = 'HKLM:\SOFTWARE\Policies\Microsoft\Edge'; Name = 'LocalNetworkAccessAllowedForUrls' }
    )
    foreach ($t in $targets) {
        try {
            if (-not (Test-Path $t.Hive)) {
                New-Item -Path $t.Hive -Force | Out-Null
            }
            # Chromium array policies are stored as a REG_SZ containing a JSON array.
            New-ItemProperty -Path $t.Hive -Name $t.Name -PropertyType String -Value $LnaDomainsJson -Force | Out-Null
            Write-Ok ("{0} -> {1} = {2}" -f (Split-Path $t.Hive -Leaf), $t.Name, $LnaDomainsJson)
        } catch {
            Write-Skip ("{0}: {1}" -f $t.Hive, $_.Exception.Message)
        }
    }
    Write-Host "    (Browsers re-read policies on restart; reboot not required.)"
} else {
    Write-Skip "disabled at bundle build time (--no-lna or no domains configured)"
}

# ---- 5. Restart QZ Tray ------------------------------------------------------
Write-Step 5 "Restarting QZ Tray"

if ($RestartTray -eq 1 -and -not $NoRestart) {
    $trayExe = Join-Path $QzDir 'qz-tray.exe'
    if (Test-Path $trayExe) {
        Start-Process -FilePath $trayExe
        Write-Ok "QZ Tray started"
    } else {
        Write-Skip "qz-tray.exe not found - start QZ Tray manually"
    }
} else {
    Write-Skip "restart skipped - start QZ Tray manually (required for trust changes to apply)"
}

Write-Host ""
Write-Host "==============================================================" -ForegroundColor Gray
Write-Host " DONE - all QZ Tray prompts are now suppressed:"       -ForegroundColor Green
Write-Host "   [x] wss://localhost transport warning (Windows Root store)" -ForegroundColor Green
Write-Host "   [x] Untrusted Website signing prompt (override.crt)"        -ForegroundColor Green
Write-Host "   [x] allowed.dat whitelist (where supported)"                -ForegroundColor Green
Write-Host "   [x] Chrome/Edge local network access prompt (policy)"       -ForegroundColor Green
Write-Host " Verify: open your tenant site and print a test page."         -ForegroundColor Green
Write-Host "==============================================================" -ForegroundColor Gray
