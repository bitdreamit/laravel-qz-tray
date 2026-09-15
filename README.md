# Laravel QZ Tray

<p align="center">
  <img src="https://img.shields.io/badge/Version-1.5.0-6f42c1?style=for-the-badge" alt="v1.5.5">
  <img src="https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/QZ%20Tray-2.2.6-0078D4?style=for-the-badge" alt="QZ Tray">
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License">
</p>

<h3 align="center">Enterprise silent printing for Laravel — zero dialogs, zero prompts, zero certificate fees.</h3>

<p align="center">
Print PDFs, images, HTML, ZPL labels, ESC/POS receipts and raw commands straight from the browser —<br>
<b>no print dialog, no "Untrusted website" pop-up, no paid QZ.io certificate</b> — with automatic<br>
browser-print fallback so your business flow never breaks.
</p>

---

> ### 🆕 v1.5.0 — the Universal Print API
> One JS file, four zero-config ways to print. The generic functions are now **built in** — no setup, no registration:
>
> ```js
> printUrl('/receipt/5.pdf');           printUrls([url1, url2, url3]);
> printPdf('data:application/pdf;base64,…');   printHtml('<h1>Ticket</h1>');
> printElement('#worklist-table');      printUrl(url, { copies: 3 });
> ```
>
> …or declaratively, with **no JavaScript at all**:
>
> ```html
> <button class="smart-print" data-qz-url="/receipt/5.pdf">Print</button>
> <button onclick="printUrl('/receipt/5.pdf')">Print</button>
> ```
>
> **Auth-protected Laravel PDF routes (mPDF `->stream()` / `->download()`) now just work** — same-origin URLs are fetched *through the page* (session cookies included) and handed to QZ Tray as bytes. Details: [Method 0](#-method-0--the-universal-print-api-v15) and [Printing mPDF PDFs behind auth](#printing-mpdf-pdfs-behind-auth-middleware).

---

## Table of Contents

- [Why Laravel QZ Tray?](#why-laravel-qz-tray)
- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Quick Start — 5 Minutes](#quick-start--5-minutes)
- [Installation — Full Step-by-Step Guide](#installation--full-step-by-step-guide)
  - [Phase 1 — Server Setup](#phase-1--server-setup)
  - [Phase 2 — Wire Up Your Layout](#phase-2--wire-up-your-layout)
  - [Phase 3 — Client Machines](#phase-3--client-machines)
  - [Phase 4 — Verify](#phase-4--verify)
- [Zero-Prompt Trust — Production Deployment](#zero-prompt-trust--production-deployment)
- [Printing with SmartPrint.js](#printing-with-smartprintjs)
  - [✨ Method 0 — The Universal Print API (v1.5+)](#-method-0--the-universal-print-api-v15)
  - [Method 1 — `class="smart-print"` (zero JS, zero attributes to learn)](#method-1--classsmart-print-zero-js-zero-attributes-to-learn)
  - [Method 2 — `onclick` function routing](#method-2--onclick-function-routing)
  - [Method 3 — Data attributes](#method-3--data-attributes)
  - [Method 4 — The full JavaScript API](#method-4--the-full-javascript-api)
  - [Printing mPDF PDFs behind auth middleware](#printing-mpdf-pdfs-behind-auth-middleware)
  - [All Data Attributes Reference](#all-data-attributes-reference)
- [Real-World Recipes](#real-world-recipes)
- [SmartPrint API Reference](#smartprint-api-reference)
- [Events Reference](#events-reference)
- [Configuration Reference](#configuration-reference)
- [Artisan Commands](#artisan-commands)
- [HTTP API Endpoints](#http-api-endpoints)
- [Multi-Tenant Support](#multi-tenant-support)
- [Database Schema](#database-schema)
- [Security](#security)
- [Environment Variables](#environment-variables)
- [Troubleshooting](#troubleshooting)
- [File Structure](#file-structure)
- [Upgrade Guide](#upgrade-guide)
- [FAQ](#faq)
- [Support & License](#support--license)

---

## Why Laravel QZ Tray?

Browsers refuse to print silently — every `window.print()` shows a dialog, and every raw-WebSocket print bridge demands a **paid certificate** (QZ Industries charges $29+/site/mo for theirs) before it stops asking "Untrusted website — Allow?".

**This package removes all three costs at once, for free:**

| Pain point | Other solutions | Laravel QZ Tray |
|---|---|---|
| Print dialog on every job | `window.print()` — dialog always | ✅ Silent printing via QZ Tray WebSocket |
| "Untrusted website" prompt on every client | Buy QZ.io's certificate | ✅ **100% free** — your own root CA + wildcard leaf (`qz:generate-ca` + `qz:generate-certificate --ca`) |
| Client-side trust deployment | Manual clicking per machine | ✅ One `setup.bat` / GPO / Intune bundle (`qz:client-bundle --zip`) |
| Auth-protected PDF routes (mPDF) | QZ has no session — prints your login page | ✅ Page-fetch hydration: bytes downloaded with cookies, sent to QZ as base64 |
| QZ Tray missing / offline | Your code crashes or hangs | ✅ Automatic browser-print fallback — the same call never dead-ends |
| Multi-tenant printers & job logs | Roll your own | ✅ Tenant-scoped printer memory + job audit table out of the box |

**Everything is included in one package:** certificate tooling, signing endpoints, a self-hosted QZ Tray JS library, a 2,000-line smart client (`smart-print.js`), printer memory per page/device/user/tenant, job logging, health diagnostics, demo pages, and a Windows client deployment bundle generator.

### Feature Highlights

- 🖨️ **All print types** — PDF, HTML, images, ZPL (Zebra), ESC/POS (thermal), raw bytes
- 🤫 **Zero pop-ups** — silent printing plus a free zero-prompt certificate toolkit (v1.4+)
- 🧠 **SmartPrint.js v1.5** — `printUrl()` / `printUrls()` / `printPdf()` / `printHtml()` / `printElement()` built in, with per-call overrides (`{ copies: 3 }`)
- 🧲 **Declarative printing** — `class="smart-print"`, `data-qz-*` attributes, or plain `onclick="printUrl(…)"` — pick your style
- 🛟 **Never dead-ends** — automatic hidden-iframe / window / new-tab / download fallback when the tray is missing, offline, or fails mid-print
- 🔐 **Server-side signing** — SHA-512 signatures, CSRF-protected, rate-limited; the private key never leaves the server
- 🏢 **Multi-tenant ready** — native UUID/bigint tenant columns, per-path printer memory, device/user/session identity priority
- 🩺 **Diagnostics** — `qz:doctor` health check, `/qz/status` posture report, interactive demo pages at `/qz/test` and `/qz/smart`
- ⚡ **Laravel 10–13**, PHP 8.1+, zero required `.env` entries

---

## How It Works

```
 Browser                         Laravel server                  Client machine
┌─────────────────┐   HTTPS    ┌──────────────────────┐         ┌─────────────────────┐
│ your Blade page │◄──────────►│ /qz/certificate      │         │ QZ Tray (Java app)  │
│                 │            │ /qz/sign (SHA-512)   │         │  · WebSocket :8181  │
│ smart-print.js  │            │ /qz/print (job log)  │         │  · verifies your    │
│  · connects ────┼──WebSocket─────────────────────┼────────►│    signed requests  │
│  · signs ───────┼──HTTPS────►│ /qz/sign             │         │  · sends raw bytes  │
│  · prints ──────┼──WebSocket─────────────────────┼────────►└──────────┬──────────┘
│  · fallback ────┼──► hidden iframe → browser print (if tray absent)     ▼
└─────────────────┘                                                    Printer
```

1. **QZ Tray** runs on each machine that physically prints. It exposes a local WebSocket on port `8181` and will only accept print requests signed by a certificate it trusts.
2. **Your Laravel app** serves its public certificate at `/qz/certificate` and signs every request at `/qz/sign` with the private key that never leaves the server.
3. **SmartPrint.js** (this package's smart client) connects the page to QZ Tray, signs jobs, remembers printers, queues and retries.
4. **The print job reaches the printer silently** — no dialog, no confirmation.
5. **If QZ Tray is missing or fails**, the exact same call degrades to a hidden-iframe browser print (or new tab / download / custom handler) — your flow continues.

**Supported print types:**

| Type | Description | Typical printers |
|------|-------------|------------------|
| `pdf` | PDF documents (URL, bytes or base64) | Any printer |
| `html` | HTML content (string, element, selector) | Any printer |
| `image` | PNG / JPEG / GIF | Any printer |
| `zpl` | Zebra Programming Language | Zebra ZD420, ZT230, GK420d |
| `escpos` | ESC/POS thermal commands | Epson TM, Star Micronics |
| `raw` | Raw byte commands | Any raw-capable printer |

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.1 or higher |
| Laravel | 10, 11, 12, or 13 |
| PHP extensions | `ext-openssl` (certificate generation/signing) |
| QZ Tray (client machines) | 2.x — only on computers that physically print |
| Browsers | Chrome / Edge / Firefox / Safari (current versions) |

> **Note:** QZ Tray is a **client-side** desktop application. It is installed on the workstation connected to the printer — **never** on your Laravel server. Mobile browsers cannot reach a local WebSocket, so mobile keeps the normal browser print dialog (the fallback engine handles this automatically).

---

## Quick Start — 5 Minutes

For the impatient: three commands, two script tags, one test print. Every step is explained in depth in the [full installation guide](#installation--full-step-by-step-guide) below.

```bash
composer require bitdreamit/laravel-qz-tray
php artisan qz:install        # publishes config + migrations + views + assets, generates your certificate
php artisan migrate           # creates qz_print_jobs + qz_printer_preferences
```

Add the CSRF meta tag and the two script tags to your layout (exact snippet in [Phase 2](#phase-2--wire-up-your-layout)), install [QZ Tray](https://qz.io/download) on the machine that will physically print, then visit **`/qz/smart`** in your browser.

That page shows the live connection status, lists your printers, and prints a real test job. If it prints — you're done. Wire up your own pages in two minutes via [Method 0](#-method-0--the-universal-print-api-v15).

> **Prefer a guided tour?** Visit **`GET /qz/setup`** — a built-in browser wizard that walks you (or your client's IT person) through certificate trust, QZ Tray installation, and verification, and serves the ready-made Windows client bundle.

---

## Installation — Full Step-by-Step Guide

This is the complete professional deployment: four phases, each ending with a ✅ verification checkpoint. Follow them in order — every phase depends on the previous one.

### Phase 1 — Server Setup

#### Step 1 — Install via Composer

```bash
composer require bitdreamit/laravel-qz-tray
```

Laravel auto-discovers the service provider — nothing else is needed. The package registers its routes, commands, views and publish groups automatically.

#### Step 2 — Run the Installer

```bash
php artisan qz:install
```

This single command does everything a fresh install needs:

- Publishes `config/qz-tray.php`
- Publishes the database migrations
- Publishes the Blade views (demo/test pages) to `resources/views/vendor/qz-tray/`
- Publishes all JavaScript and CSS assets to `public/vendor/qz-tray/`
- Generates your QZ signing certificate automatically

**Expected output:**

```
🚀 Installing Laravel QZ Tray Package...

📁 Publishing configuration...
🗃️  Publishing migrations...
📄 Publishing blade views...
📦 Publishing JavaScript assets...
🔐 Generating certificate...
  Generating private key...
  Creating certificate signing request...
  ✅ Certificate generated successfully!
  📄 Certificate: /var/www/html/storage/qz/digital-certificate.txt
  🔑 Private key:  /var/www/html/storage/qz/private-key.pem

✅ QZ Tray installed successfully!
```

Useful flags:

```bash
php artisan qz:install --force     # re-install, overwriting previously published files
php artisan qz:install --no-cert   # publish everything but skip certificate generation
```

> **Production tip:** on production you usually generate the certificate explicitly (so you can choose the domain, see [Zero-Prompt Trust](#zero-prompt-trust--production-deployment)) rather than letting the installer create a generic self-signed one: `php artisan qz:install --no-cert` first, then `qz:generate-certificate --ca --domain=…`.

#### Step 3 — Run Migrations

```bash
php artisan migrate
```

Creates two tables:

| Table | Purpose |
|---|---|
| `qz_print_jobs` | Optional print-job audit log (what was printed, where, when, status) |
| `qz_printer_preferences` | Server-side backup of printer memory per device/user/session/path |

> **Multi-tenant / UUID apps:** set `QZ_JOB_ID_TYPE` in `.env` **before** the first migrate if your project uses bigint primary keys. See [Multi-Tenant Support](#multi-tenant-support).

#### Step 4 — Confirm the Server Is Healthy

```bash
php artisan qz:doctor
```

Checks the certificate (fingerprint, issuer, expiry), the private key, the database tables and the config — and tells you exactly what (if anything) is wrong. Then hit the status endpoint:

```
GET /qz/status
```

```json
{
  "success": true,
  "status": "operational",
  "certificate": "present",
  "private_key": "present"
}
```

✅ **Checkpoint 1:** `/qz/status` reports `operational` and `qz:doctor` reports no failures.

---

### Phase 2 — Wire Up Your Layout

Every page that prints needs the CSRF meta tag (for the signing endpoints) and the two self-hosted script tags. Add them once to your main layout — `resources/views/layouts/app.blade.php` or equivalent:

```blade
<!DOCTYPE html>
<html>
<head>
    <!-- Required: CSRF token for /qz/sign and /qz/print -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Optional but recommended: page-wide client config -->
    <script>
        window.QZ_CONFIG = {
            fallbackMode: 'auto',   // browser-print fallback when the tray is missing
            fetchMode:    'auto',   // page-fetch same-origin URLs (auth routes work)
        };
    </script>
</head>
<body>
    {{-- your content --}}

    <!-- 1) QZ Tray WebSocket library — self-hosted, ships with this package -->
    <script src="{{ asset('vendor/qz-tray/js/qz-tray.min.js') }}"></script>

    <!-- 2) SmartPrint — the smart client (must come AFTER qz-tray.min.js) -->
    <script src="{{ asset('vendor/qz-tray/js/smart-print.js') }}"></script>
</body>
</html>
```

> **The CSRF meta tag is required.** SmartPrint sends it with every `POST /qz/sign` and `POST /qz/print`. Without it you will get `419 CSRF token mismatch`.

**v1.5.5 — skip the manual `<script>` tags and get automatic cache busting:**

```blade
    {{-- replaces the two <script> tags above — ?v={filemtime} busts the
         browser cache automatically on every file change --}}
    @qzTrayScripts

    {{-- your own app files get the same treatment --}}
    @qzTrayScripts(['js/lab-receipt.js'])
```

The URL only changes when the file actually changes, so browsers cache
normally between deploys — no more deleting cache by hand after every JS
update.

**Multi-tenant apps** — tag every print with the tenant once, page-wide:

```blade
<script>
    window.QZ_CONFIG = {
        tenantId: '{{ auth()->user()?->tenant_id }}',
        // or: projectId: '{{ $project->id }}',
    };
</script>
```

✅ **Checkpoint 2:** view-source on any page shows both script tags resolving to real files (`HTTP 200`), and the console shows no `SmartPrint` errors.

---

### Phase 3 — Client Machines

Each computer that will physically print needs **QZ Tray** installed. It runs in the system tray and listens on `localhost:8181`.

**Option A — manual install (fine for a few machines):**

Download from [qz.io/download](https://qz.io/download) — or link your users straight to the package's info endpoints: `/qz/installer/windows`, `/qz/installer/linux`, `/qz/installer/macos`. After installation QZ Tray starts automatically with the OS; there is **no client configuration needed** for the basic flow.

**Option B — zero-prompt bundle (recommended for fleets):**

Build a signed deployment bundle on the server and run `setup.bat` (as admin) on each Windows client — or push it silently via GPO/Intune. This also removes the "Untrusted website" prompt entirely. Full details in [Zero-Prompt Trust](#zero-prompt-trust--production-deployment):

```bash
php artisan qz:generate-ca
php artisan qz:generate-certificate --force --ca --domain=*.yourdomain.com,yourdomain.com
php artisan qz:client-bundle --zip
```

The bundle is also downloadable at any time from `GET /qz/client-bundle`.

✅ **Checkpoint 3:** QZ Tray icon is visible in the client's system tray; `netstat -an | findstr 8181` shows it listening (Windows).

---

### Phase 4 — Verify

Run through this checklist on a client machine (not the server):

1. **Open `/qz/smart`** — the interactive SmartPrint demo.
   - Status indicator turns **green** (WebSocket connected).
   - The printer dropdown lists the machine's real printers.
2. **Print a test job** — click *Print sample PDF*. The page at `/qz/test/pdf` generates a streaming test PDF; the printer produces it silently.
3. **Test the signing pipeline end-to-end:**

   ```bash
   curl -X POST https://yourapp.test/qz/test-sign -H "Accept: application/json"
   # → "message": "Signing works correctly"
   ```

4. **Check the job log** (optional, if `QZ_LOGGING_ENABLED=true`):

   ```php
   DB::table('qz_print_jobs')->latest()->first();   // the test job is there
   ```

✅ **Checkpoint 4:** a physical page came out of the printer with no dialogs. You're production-ready — continue to [Zero-Prompt Trust](#zero-prompt-trust--production-deployment) to remove the one remaining prompt, and [Printing with SmartPrint.js](#printing-with-smartprintjs) to wire up your pages.

### Publishing Assets — Manual Control

`php artisan qz:install` runs every publish group at once plus certificate generation — right for a first install. Use the individual tags when you need finer control: republishing only assets after an upgrade, keeping generated views out of CI, or Docker builds that shouldn't touch `storage/`.

| Tag | Publishes | Destination |
|-----|-----------|-------------|
| `qz-config` | `config/qz-tray.php` | `config/qz-tray.php` |
| `qz-migrations` | `qz_print_jobs` + `qz_printer_preferences` migrations | `database/migrations/` |
| `qz-blade` | Demo/test Blade views (`smart`, `default`, `example`, wizard) | `resources/views/vendor/qz-tray/` |
| `qz-assets` | `smart-print.js`, `printer-switcher.js`, `printer-status.js`, adapters, vendored `qz-tray.min.js` (+ unminified), CSS, fonts | `public/vendor/qz-tray/` |
| `qz-installers` | QZ Tray desktop installers (Windows/macOS/Linux), if bundled | `public/vendor/qz-tray/installers/` |

```bash
# Publish everything without the installer's certificate step:
php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider"

# Overwrite already-published files (e.g. after upgrading the package):
php artisan vendor:publish --tag=qz-assets --force
```

> Publishing only *copies* files — re-run `php artisan migrate` if you re-published `qz-migrations`.

---

## Zero-Prompt Trust — Production Deployment

By default, the first print on each client shows QZ Tray's *"Untrusted website — Allow?"* dialog. QZ Tray keys this prompt to the **certificate fingerprint, not the domain**, so the fix is to give every client a trust root it already believes in. This package ships a complete, **100% free** toolkit — no QZ.io certificate fees.

**Pick ONE path:**

| | Path A — Real CA certificate | Path B — Own Root CA + override.crt ⭐ recommended | Path C — Shared self-signed pair |
|---|---|---|---|
| Best when | You already have Let's Encrypt / cPanel AutoSSL | You control the Windows clients | Quick small setups |
| Client work | **Zero** | One `setup.bat` (or GPO) per machine | One "Always Allow" click per machine |
| Sign-trust mechanism | Real CA chain — QZ Tray trusts silently | Your own root as `override.crt` | Fingerprint "Always Allow" entry |
| Wildcard / multi-tenant | Wildcard cert covers all subdomains | One wildcard leaf covers all subdomains | One shared fingerprint covers all subdomains |
| Cost | Free | Free | Free |

### Path A — Import a Real CA Certificate (zero client deployment)

```bash
php artisan qz:certificate:import \
    --cert=/etc/letsencrypt/live/app.example.com/fullchain.pem \
    --key=/etc/letsencrypt/live/app.example.com/privkey.pem
```

Use the **fullchain** file (leaf + intermediates). A wildcard certificate covers every subdomain at once.

Keep it renewing automatically — `qz:watch-certificate` re-imports after every Let's Encrypt renewal:

```env
QZ_WATCH_CERT=/etc/letsencrypt/live/app.example.com/fullchain.pem
QZ_WATCH_KEY=/etc/letsencrypt/live/app.example.com/privkey.pem
```

> Sites behind **Cloudflare**: your free Origin Certificate (`*.yourdomain.com`, valid up to 15 years) doubles as the shared QZ certificate. See [docs/multi-domain.md](docs/multi-domain.md).

### Path B — Your Own Root CA + override.crt (recommended when you control the clients)

```bash
# 1. Create your own free root CA
php artisan qz:generate-ca

# 2. Wildcard leaf signed by that CA — covers ALL subdomains/tenants.
#    Include the bare domain too.
php artisan qz:generate-certificate --force --ca \
    --domain=*.yourdomain.com,yourdomain.com

# 3. Build the Windows deployment bundle (+ .zip)
php artisan qz:client-bundle --zip --lna-domains='[*.]yourdomain.com'
```

`qz:client-bundle` produces `storage/qz/client-bundle/` containing everything a client needs. Run `setup.bat` **as administrator** on each Windows machine — or deploy `qz-client-setup.ps1` silently via **GPO / Intune**. The script installs, in one shot:

| Component | Installed to | Effect |
|---|---|---|
| `root-ca.crt` → machine Root store | `certutil -addstore -f Root …` | localhost TLS trust — no browser certificate warning |
| `override.crt` | `%PROGRAMFILES%\QZ Tray\override.crt` | QZ Tray signs against **your** root — the signing prompt disappears |
| `allowed.dat` entry | `%APPDATA%\qz\allowed.dat` | Equivalent of pressing "Always Allow" once |
| Chrome/Edge LNA policy | Registry | Silently allows `localhost:8181` WebSocket in Chromium browsers |

The bundle can be re-downloaded any time at **`GET /qz/client-bundle`**, and the step-by-step wizard for client IT staff lives at **`GET /qz/setup`**.

> **Certificate rotation:** the signing trust is pinned to your root CA — when a leaf expires, re-issue the leaf with `qz:generate-certificate --force --ca …` and redeploy **only the server certificate**; clients keep trusting. Rotate the root itself rarely, and redeploy the new `override.crt` *before* switching (`qz:override:export --show`).

### Path C — Shared Self-Signed Keypair (smallest setups)

Ensure every subdomain shares the identical certificate pair, then press **"Always Allow"** (not plain "Allow" — that one is session-only and is why the prompt "comes back every time"):

```bash
# main domain
php artisan qz:certificate:export
# each subdomain
php artisan qz:certificate:import --pfx=qz-tray.pfx --password=…
# …or point every vhost's .env at the same files:
#   QZ_CERT_PATH=/home/shared/qz/digital-certificate.txt
#   QZ_KEY_PATH=/home/shared/qz/private-key.pem
```

Verify with `php artisan qz:doctor` — identical SHA-1 fingerprints across subdomains = one trust decision covers them all.

> **Full deep-dive with diagrams:** [docs/zero-prompt.md](docs/zero-prompt.md) and [docs/multi-domain.md](docs/multi-domain.md).

---

## Printing with SmartPrint.js

`SmartPrint` is the JavaScript client this package publishes to `public/vendor/qz-tray/js/smart-print.js`. It handles the WebSocket connection, request signing, queueing, retries, printer memory, content hydration and fallback automatically.

**There are four ways to trigger a print. Pick whichever fits the page you're building — they all end up in the same engine, with the same fallback behavior and the same per-call options.**

| # | Style | Best for | JS required? |
|---|---|---|---|
| 0 | **Universal functions** (`printUrl`, `printHtml`, …) | Real application code, jQuery handlers, framework apps | Inline calls only |
| 1 | **`class="smart-print"`** | Simple buttons/links in Blade — zero wiring | None |
| 2 | **`onclick="printUrl(…)"`** | Quick inline handlers | One-liner |
| 3 | **`data-qz-*` attributes** | Declarative buttons with per-button options | None |
| 4 | **`SmartPrint.*` API** | Full programmatic control | Yes |

<a id="-method-0--the-universal-print-api-v15"></a>
### ✨ Method 0 — The Universal Print API (v1.5+)

The generic functions are **built into `smart-print.js`** — no `define()`, no setup. Call them from anywhere: Blade, jQuery handlers, DataTables row buttons, React/Vue/Livewire, or the browser console. Every function accepts almost anything as input, detects PDF vs image vs HTML from the HTTP `Content-Type`, and falls back to the browser print dialog automatically when QZ Tray is not installed, not connected, or fails.

```js
// Built-in — available as SmartPrint methods AND guarded window globals:
printUrl('/receipt/5.pdf');                        // PDF, image or HTML — auto-detected
printUrls([url1, url2, url3]);                     // sequential batch
printPdf('data:application/pdf;base64,JVBERi0x…'); // direct PDF bytes, no URL needed
printPdfs([src1, src2]);                           // batch, any mix of url/base64/data:
printImage('/barcode/123.png');                    // native QZ image printing
printImages([img1, img2]);
printHtml('<h1>Queue Ticket</h1><p>A-042</p>');    // raw HTML string
printElement('#worklist-table');                   // any element by id/class/selector
printElements(['#a', '.b']);                       // several elements, in order
printPage();                                       // the current page
printUrl(url, { copies: 3 });                      // per-call overrides
printAnyUrl(url);                                  // readability alias of printUrl
printAnyUrls([url1, url2]);                        // readability alias of printUrls
```

**What every function accepts as input:**

| Input | Example | Behavior |
|---|---|---|
| URL string | `printUrl('/receipt/5.pdf')` | Fetched (see [hydration](#printing-mpdf-pdfs-behind-auth-middleware)) or handed to QZ; type auto-detected |
| `data:` URI | `printPdf('data:application/pdf;base64,…')` | Printed directly — no server round-trip |
| Raw base64 | `printPdf('JVBERi0xLj…')` | Detected by magic bytes (`%PDF-`, PNG, JPEG…) |
| Raw HTML string | `printHtml('<h1>Hi</h1>')` | Sent as HTML payload |
| CSS selector | `printElement('#worklist-table')` | Element snapshotted **with the page stylesheets** — the printout matches the screen |
| `HTMLElement` / jQuery object | `printElement(document.body)` | Same snapshot behavior |
| Object spec | `printUrl({ url, printer, copies })` | Per-input options |
| Lazy function | `printUrl(() => currentReceiptUrl())` | Resolved at call time |
| Array of any | `printUrls([u1, sel2, lazy3])` | Sequential batch, in order |

**Per-call options** (third argument / second object):

```js
printUrl('/receipt/5.pdf', {
    copies: 3,               // number of copies
    printer: 'XP-80C',       // exact OS printer name, or a registered alias
    profile: 'small',        // paper: 'default' | 'small' (80mm) | 'label' (100×150mm)
    fallback: 'download',    // 'auto' | 'iframe' | 'window' | 'newtab' | 'download' | 'queue' | 'none' | fn
    fetch: true,             // force page-fetch hydration on/off for this call
    type: 'pdf',             // force the payload type (skip auto-detection)
    filename: 'receipt.pdf', // hint for the download fallback
    onComplete: job => {},   // success callback
    onError: (err, job) => {}, // failure callback
});
```

**The result contract** — promises never throw unhandled. A call resolves with a clear object:

```js
const r = await printUrl('/receipt/5.pdf');
// { jobId, success: true }                          — printed via QZ Tray
// { jobId, success: false, fallback: true }         — QZ unavailable, browser printed it
// { success: false, reason: 'unexpected-response',
//   contentType: 'text/html', status: 200 }         — auth route answered a login page; nothing printed
// rejection                                         — only in 'queue' mode or raw-data errors
```

**Optional presets** — keep friendly business names with baked-in printer/profile choices (custom `define()` templates always take precedence over the built-ins):

```js
// once, after smart-print.js is loaded:
SmartPrint.define({
    printLabReceipt:  { type: 'pdf', profile: 'a4' },
    printLabReceipts: { type: 'pdf', profile: 'a4' },
    printWorkList:    { type: 'pdf', profile: 'small', printer: 'receipt' },
    printLabel:       { type: 'pdf', profile: 'label', printer: 'label' },
});
SmartPrint.aliasPrinter('receipt', 'XP-80C');    // friendly name → real OS printer name
SmartPrint.aliasPrinter('label',   'Zebra GK420d');

printLabReceipt('/receipt/5.pdf');               // now a real function everywhere
```

> The legacy names `printLabReceipt` / `printLabReceipts` are already registered as aliases of `printUrl` / `printUrls` — old code keeps working even without the `define()` block above.

### Method 1 — `class="smart-print"` (zero JS, zero attributes to learn)

**Any** button or link with `class="smart-print"` prints. No `data-qz-action`, no JavaScript — the library routes the click for you. Works on `<button>`, `<a>`, `<div>`, anything clickable:

```html
<button class="smart-print" data-qz-url="/receipt/5.pdf">Print</button>
<a class="smart-print" href="/receipt/5.pdf">Print receipt</a>   <!-- plain href = the document -->
<button class="smart-print" data-qz-urls="/r/1.pdf|/r/2.pdf|/r/3.pdf">Batch</button>
<button class="smart-print" data-qz-element="#worklist-table">Worklist</button>
<button class="smart-print" data-qz-html="&lt;h1&gt;Queue Ticket&lt;/h1&gt;">Ticket</button>
<button class="smart-print" data-qz-data="data:application/pdf;base64,JVBERi0…">Bytes</button>
<button class="smart-print" data-qz-url="/receipt/5.pdf" data-qz-copies="3" data-qz-printer="receipt">x3</button>
```

**Routing precedence** for one element — the first match wins:

1. `data-qz-action` → run that named action (checked first, see Method 3)
2. The element's **own `onclick`** → left *entirely* to that function (never double-prints; navigation is still suppressed)
3. `data-qz-urls` → `printUrls` batch
4. `data-qz-url` / `data-url` → `printUrl`
5. `data-qz-html` → `printHtml`
6. `data-qz-element` / `data-qz-target` → `printElement`
7. `data-qz-data` / `data-qz-base64` → auto-detected base64 / `data:` URI
8. Plain `<a href>` → print the href (anchors like `#`, `javascript:`, `mailto:`, `tel:` are ignored)
9. Nothing to print → a silent console hint; the page is never broken

Buttons inside `<form>`s are safe — the click's default action (submit) is suppressed.

### Method 2 — `onclick` function routing

Because the universal functions are guarded `window` globals, a plain inline `onclick` works with or without the class:

```html
<button onclick="printUrl('/receipt/5.pdf')">Print</button>
<button onclick="printElement('#worklist-table', { copies: 2 })">Worklist x2</button>
<a href="#" onclick="printUrls(['/r/1.pdf','/r/2.pdf']); return false;">Batch</a>
```

Any name you register with `SmartPrint.define()` becomes a window global too (guarded — an existing app function is never overwritten), so `onclick="printLabReceipt('/receipt/5.pdf')"` works as well.

### Method 3 — Data attributes

The classic declarative layer — named actions, per-button options, auto-print on load:

```html
{{-- Named action (routed through SmartPrint.define templates) --}}
<button data-qz-action="printUrl" data-qz-url="/receipt/5.pdf">Print</button>
<button data-qz-action="printUrls" data-qz-urls="/r/1.pdf|/r/2.pdf|/r/3.pdf">Batch</button>
<button data-qz-action="printElement" data-qz-target="#worklist-table">Worklist</button>
<button data-qz-action="printUrl" data-qz-url="/receipt/5.pdf" data-qz-copies="3">x3</button>

{{-- Direct print attributes (legacy, still fully supported) --}}
<button data-qz-print="/invoices/123.pdf">Print Invoice</button>
<button data-qz-print="/invoices/123.pdf" data-qz-printer="HP LaserJet M404" data-qz-copies="2">
    Print 2 Copies
</button>
<button data-qz-print="" data-qz-type="zpl" data-qz-printer="Zebra ZD420"
        data-qz-data="^XA^FO50,50^ADN,36,20^FDHello World^FS^XZ">
    Print Label
</button>

{{-- Auto-print on page load (optionally delayed) --}}
<div data-qz-auto-print="{{ route('invoices.pdf', $invoice) }}" data-qz-type="pdf"></div>
<div data-qz-auto-print="/receipts/99.pdf" data-qz-delay="2000"></div>
```

### Method 4 — The full JavaScript API

Complete programmatic control — see [SmartPrint API Reference](#smartprint-api-reference) for every method:

```js
// Universal API (routed through the full pipeline: resolvers + hydration + fallback)
SmartPrint.printUrl('/receipt/5.pdf', { copies: 3 });
SmartPrint.printHtml('<h1>Ticket</h1>');
SmartPrint.printElement('#worklist-table');

// Core API (direct queue, no action pipeline)
SmartPrint.print('/invoices/123.pdf');
SmartPrint.print({
    url:     '/invoices/123.pdf',
    type:    'pdf',
    printer: 'HP LaserJet M404',
    copies:  2,
    profile: 'default',   // 'default' | 'small' (80mm) | 'label' (100×150mm)
});
SmartPrint.printZPL('^XA^FO50,50^ADN,36,20^FDHello^FS^XZ', 'Zebra ZD420');
SmartPrint.printESC('\x1B\x40Hello Thermal!\n\n\n', 'Epson TM-T88');
SmartPrint.printRaw('\x1B\x40Test\n', 'raw', 'My Printer');

// Shorthand globals (collision-guarded)
smartPrint('/doc.pdf', { copies: 2 });
smartPrintZPL(zpl, 'Zebra');
smartPrintESC(esc, 'Epson TM');
smartPrintHTML('<h1>Hi</h1>');
smartPrintPdf(base64OrUrl);
printElement('#some-id');
smartPrintRun('printLabReceipt', '/receipt/5.pdf');
smartPrintDefine('printLabel', { type: 'pdf', profile: 'label' });
```

### Printing mPDF PDFs behind auth middleware

**The answer to "does `PDF::stream()` / `->download()` work? — yes, both, even behind `auth`.**

QZ Tray downloads URLs **itself** — with no browser session and no cookies. An auth-protected route would hand QZ your login page (the classic `Cannot parse … End-of-File` error). v1.5 solves this inside the browser:

1. `printUrl()` **fetches same-origin URLs through the page first** — session cookies flow automatically (`credentials: 'same-origin'`).
2. The response `Content-Type` picks the print path: `application/pdf` → QZ pdf payload, `image/*` → QZ image payload, `text/html` → QZ html payload.
3. The **bytes** (base64) go to QZ Tray — the tray never needs its own session.
4. `Content-Disposition: attachment` (from `->download()`) is ignored — only the bytes matter.

```php
// routes/web.php — a normal, protected mPDF route. Nothing special.
Route::get('/receipt/{id}.pdf', [ReceiptController::class, 'pdf'])->middleware('auth');

public function pdf(Request $request, $id)
{
    $pdf = \Mpdf\Mpdf::loadView('receipts.show', compact('id'));
    return $pdf->stream('receipt-'.$id.'.pdf');   // ->download() works identically
}
```

```js
printUrl('/receipt/5.pdf');   // that is the whole integration
```

**Misdirected-response guard:** if a `.pdf`/image URL answers with `text/html` (login redirect, 404 page) or JSON, it is detected and **not** printed — the call resolves `{ success: false, reason: 'unexpected-response', contentType, status }` instead of feeding garbage to QZ. Unlabeled `application/octet-stream` responses are sniffed by magic bytes (`%PDF-`, PNG, JPEG, GIF).

**Global policy** (`window.QZ_CONFIG.fetchMode`):

| Mode | Behavior |
|---|---|
| `'auto'` *(default)* | Fetch same-origin URLs through the page; pass cross-origin URLs to QZ directly |
| `'always'` | Fetch every URL through the page (also works for CORS-enabled cross-origin routes) |
| `'never'` / `false` | Legacy behavior — hand every URL to QZ as-is |

Per call: `printUrl(url, { fetch: true | false })` or the `data-qz-fetch="true|false"` attribute.

### All Data Attributes Reference

| Attribute | Applies to | Default | Description |
|-----------|-----------|---------|-------------|
| `class="smart-print"` | any element | — | Marks the element as a print trigger (routing precedence above) |
| `data-qz-url` | click triggers | — | URL to print (auto-detects PDF/image/HTML) |
| `data-url` | click triggers | — | Alias of `data-qz-url` |
| `data-qz-urls` | click triggers | — | Pipe-separated batch: `"/a.pdf|/b.pdf|/c.pdf"` — printed sequentially |
| `data-qz-html` | click triggers | — | Raw HTML string to print |
| `data-qz-element` / `data-qz-target` | click triggers | — | CSS selector of the element to print |
| `data-qz-data` / `data-qz-base64` | click triggers | — | Raw payload (base64 / `data:` URI / raw data for zpl/escpos) |
| `data-qz-action` | click triggers | — | Named action to run (built-in or `SmartPrint.define()`) |
| `data-qz-print` | click triggers | — | Legacy direct-print URL (still supported) |
| `data-qz-auto-print` | any element | — | URL printed automatically when the element appears |
| `data-qz-type` | any trigger | auto | Force type: `pdf`, `html`, `image`, `zpl`, `escpos`, `raw` |
| `data-qz-printer` | any trigger | saved/default | Printer name (or a registered alias) |
| `data-qz-copies` | any trigger | `1` | Number of copies |
| `data-qz-profile` | any trigger | `default` | `default` \| `small` (80mm) \| `label` (100×150mm) |
| `data-qz-delay` | any trigger | `0` | Milliseconds to wait before printing |
| `data-qz-fetch` | url triggers | config | Force page-fetch hydration: `"true"` / `"false"` |
| `data-qz-filename` | any trigger | — | Filename hint for the download fallback |

---

## Real-World Recipes

Copy-paste starting points for the situations you actually ship.

### 📄 Print an Invoice (mPDF, auth-protected, zero dialogs)

```blade
{{-- resources/views/invoices/show.blade.php --}}
<div class="invoice-actions">
    <button class="smart-print" data-qz-url="{{ route('invoices.pdf', $invoice) }}">
        🖨 Print Invoice #{{ $invoice->number }}
    </button>

    <button class="smart-print" data-qz-url="{{ route('invoices.pdf', $invoice) }}"
            data-qz-copies="2" data-qz-printer="Office HP LaserJet">
        🖨 Print 2 Copies
    </button>
</div>
```

```php
// routes/web.php — a completely normal Laravel route
Route::get('/invoices/{invoice}/pdf', function (Invoice $invoice) {
    return \Mpdf\Mpdf::loadView('invoices.pdf', compact('invoice'))
        ->stream('invoice-'.$invoice->number.'.pdf');
})->name('invoices.pdf')->middleware('auth');
```

That is the entire integration — hydration, signing, fallback and printer memory are automatic.

### 🏷 Print a ZPL Label (Zebra)

```blade
<button class="smart-print"
        data-qz-type="zpl"
        data-qz-printer="Zebra ZD420"
        data-qz-data="^XA
^FO50,50^ADN,36,20^FD{{ $product->name }}^FS
^FO50,100^ADN,24,14^FD{{ $product->sku }}^FS
^FO50,150^BCN,80,Y,N,N^FD{{ $product->barcode }}^FS
^XZ">
    🏷 Print Label
</button>
```

Server-side ZPL route:

```php
Route::get('/api/labels/{product}/zpl', function (Product $product) {
    $zpl = "^XA\n^FO50,50^ADN,36,20^FD{$product->name}^FS\n"
         . "^FO50,100^BCN,80,Y,N,N^FD{$product->barcode}^FS\n^XZ";
    return response($zpl, 200, ['Content-Type' => 'text/plain']);
});

// JS side — one line:
printUrl('/api/labels/7/zpl', { type: 'zpl', printer: 'Zebra ZD420' });
```

Or build labels programmatically with the bundled adapter (`public/vendor/qz-tray/js/adapters/zpl.js`):

```js
const label = ZPL.label()
    .text('Product Name', 50, 50, { size: 'large' })
    .barcode('1234567890', 50, 150, { height: 80 })
    .build();

SmartPrint.printZPL(label, 'Zebra ZD420');
```

### 🧾 Print an ESC/POS Receipt (thermal)

Build receipts with the bundled adapter (`adapters/escpos.js`):

```js
const receipt = ESCPOS.receipt()
    .initialize()
    .align('center').bold(true).text('MY STORE').bold(false).align('left')
    .divider()
    .line('Item 1', '$10.00')
    .line('Item 2', '$5.50')
    .divider()
    .bold(true).line('TOTAL', '$15.50').bold(false)
    .feed(3).cut()
    .build();

SmartPrint.printESC(receipt, 'Epson TM-T88VI');
```

Or generate the receipt server-side and print the fetched bytes:

```php
public function receipt(Order $order)
{
    $out  = "\x1B\x40\x1B\x61\x01" . $order->store_name . "\n\x1B\x61\x00";
    foreach ($order->items as $item) {
        $out .= str_pad($item->name, 24) . str_pad('$'.$item->price, 8, ' ', STR_PAD_LEFT) . "\n";
    }
    $out .= "\n\n\n\x1D\x56\x41";
    return response($out, 200, ['Content-Type' => 'application/octet-stream']);
}
```

```js
fetch('/orders/42/receipt').then(r => r.text()).then(d => SmartPrint.printESC(d, 'Epson TM-T88'));
```

### 📦 Sequential Batch Printing

```js
// Order guaranteed — jobs are queued and printed one at a time:
printUrls(['/packing-slip/1.pdf', '/label/1.pdf', '/label/2.pdf']);

// Mixed content, lazy inputs, per-item options:
printUrls([
    '/invoice/9.pdf',
    () => '/receipt/' + currentId() + '.pdf',
    { url: '/barcode/9.png', printer: 'Label' },
    'data:application/pdf;base64,JVBERi0x…',
]);

// Declarative, no JS at all:
// <button class="smart-print" data-qz-urls="/r/1.pdf|/r/2.pdf|/r/3.pdf">Batch</button>
```

### 🖨️ Auto-Print After Save (server → page)

```php
// Controller — after the order is created:
return redirect()->route('orders.show', $order)
    ->with('auto_print', route('orders.pdf', $order));
```

```blade
{{-- layout or show view --}}
@if(session('auto_print'))
    <div data-qz-auto-print="{{ session('auto_print') }}" data-qz-type="pdf" data-qz-delay="500"></div>
@endif
```

### 🖱 Printer Selection & Memory

```js
// Show the picker modal (also: press Ctrl+Shift+P anywhere):
SmartPrint.showPrinterSwitcher();

// Set/remember a printer — per page by default, or globally:
SmartPrint.setPrinter('HP LaserJet M404');            // remembered for this URL path
SmartPrint.setPrinter('HP LaserJet M404', 'global');  // remembered everywhere

// Friendly aliases — views reference 'receipt', real names live in ONE line:
SmartPrint.aliasPrinter('receipt', 'XP-80C');
SmartPrint.aliasPrinter('label',   'Zebra GK420d');
printUrl('/receipt/5.pdf', { printer: 'receipt' });   // resolved at PRINT time
```

Printer memory is remembered per `(tenant, device/user/session, URL path)` — in `localStorage` on the client and mirrored to the `qz_printer_preferences` table, so a fresh browser on the same workstation picks its printer back up automatically.

### 🛟 Fallback Engine — Never Dead-End

When QZ Tray is missing, offline, fails mid-print, or the user cancels printer selection, **the same call degrades automatically**:

```js
// Per call / per action:
printUrl('/receipt/5.pdf', { fallback: 'download' });

// Global default:
window.QZ_CONFIG = { fallbackMode: 'auto' };
```

| Mode | Behavior |
|---|---|
| `'auto'` *(default)* | Hidden-iframe browser print — silent, no new tab |
| `'iframe'` | Force the hidden-iframe engine |
| `'window'` | `window.print()` of the document |
| `'newtab'` | Open the document in a new tab |
| `'download'` | Download the file instead |
| `'queue'` | Park the job and retry when the tray reconnects (pre-1.4 behavior) |
| `'none'` | Do nothing — resolve with the failure reason |
| `(job) => …` | Your own function — full control |

Optional UI hooks:

```js
window.QZ_CONFIG = {
    notify: true,                      // toast when a job degrades to the browser
    onFallback: job => analytics.track('browser-fallback', job),
};
SmartPrint.on('fallback-print', job => console.log('browser fallback used', job));
```

### 📡 Wire Up Your UI with Events

```js
SmartPrint.on('connected', data => {
    document.getElementById('printer-status').textContent = '🟢 QZ Tray Connected';
    document.getElementById('printer-name').textContent = SmartPrint.getCurrentPrinter() || 'None selected';
});

SmartPrint.on('connection-failed', () => {
    document.getElementById('printer-status').textContent = '🔴 QZ Tray Offline';
    document.getElementById('install-link').style.display = 'block';
});

SmartPrint.on('job-completed', data => {
    fetch('/print-log', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ url: data.job.url, printer: data.job.printer }),
    });
});
```

Full event list: [Events Reference](#events-reference).

### 🧩 Framework Integration

`SmartPrint` is a plain object on `window` — call it from anywhere:

```js
// React
window.printUrl(`/invoices/${id}.pdf`);

// Vue
printUrl({ url: `/invoices/${this.invoice.id}.pdf`, copies: 2 });

// Livewire (@script)
printUrl(`/invoices/${$wire.invoiceId}.pdf`);

// jQuery
$('.print-btn').on('click', () => printUrl('/receipt/5.pdf'));

// Or skip JS entirely: class="smart-print" works with any framework's markup.
```

### 🖥 Server-Side Triggered Printing

Printing is always executed by the browser (the printer is attached to the *client*), but your server decides *when* — return a page with an auto-print directive, or a flash message (recipe above). For raw/audit logging, every print also `POST`s to `/qz/print`, which writes to `qz_print_jobs` when the tables exist.

---

## SmartPrint API Reference

### Universal Print API (v1.5+)

| Method | Signature | Description |
|---|---|---|
| `printUrl(input, options?)` | `Promise<Result>` | Print any URL — type auto-detected from Content-Type |
| `printUrls(input, options?)` | `Promise<Result[]>` | Sequential batch of anything `printUrl` accepts |
| `printAnyUrl` / `printAnyUrls` | alias | Readability aliases of the above |
| `printPdf(input, options?)` | `Promise<Result>` | Force `pdf` type — URL, `data:` URI or raw base64 |
| `printPdfs(input, options?)` | `Promise<Result[]>` | Batch pdf |
| `printImage(input, options?)` | `Promise<Result>` | Force `image` type — URL, `data:` URI, base64 |
| `printImages(input, options?)` | `Promise<Result[]>` | Batch image |
| `printHtml(input, options?)` | `Promise<Result>` | Force `html` type — HTML string, element or selector |
| `printElement(input, options?)` | `Promise<Result>` | Print a DOM element (matched by selector) — throws clearly if no match |
| `printElements(input, options?)` | `Promise<Result[]>` | Batch elements |
| `printPage(options?)` | `Promise<Result>` | Snapshot the whole page (`{ mode: 'browser' }` = plain `window.print()`) |

`Result` = `{ jobId, success, fallback?, cancelled?, reason? }` — never throws unhandled (see the [result contract](#-method-0--the-universal-print-api-v15)).

### Smart Actions

| Method | Description |
|---|---|
| `SmartPrint.define(nameOrMap, template?)` | Register (or override) a named action; auto-creates a **guarded** window global |
| `SmartPrint.run(name, input?, overrides?)` | Run a registered action programmatically |
| `SmartPrint.has(name)` | Is the action registered? |
| `SmartPrint.actions()` | List all registered action names |
| `SmartPrint.aliasPrinter(alias, realPrinter?)` | Map a friendly name to a real OS printer (resolved at print time); `null` unmaps |

### Core Printing

| Method | Description |
|---|---|
| `SmartPrint.print(urlOrOptions, options?)` | Direct queue print — returns a `Promise` that resolves `{ jobId, success }` |
| `SmartPrint.printRaw(data, type?, printer?)` | Raw bytes |
| `SmartPrint.printZPL(zpl, printer?)` | ZPL payload |
| `SmartPrint.printESC(escpos, printer?)` | ESC/POS payload |

### Printer Management

| Method | Description |
|---|---|
| `SmartPrint.getPrinters()` | `Promise<string[]>` — real printer list via WebSocket |
| `SmartPrint.getCurrentPrinter()` | Currently selected printer name, or `null` |
| `SmartPrint.setPrinter(name, scope?)` | Remember printer — `'path'` (default) or `'global'` |
| `SmartPrint.showPrinterSwitcher()` | Open the printer picker modal |
| `SmartPrint.getDeviceId()` | Persistent workstation UUID (sent as `X-Device-Id` automatically) |

### Health & Connection

| Method | Description |
|---|---|
| `SmartPrint.status()` | Full snapshot: `{ qzLibrary, connected, printers, currentPrinter, queued, failed, fallbackMode, actions }` |
| `SmartPrint.whenReady(timeoutMs?)` | `Promise<{ ok, reason }>` — settles as soon as the tray is connected *or* definitively unavailable (`'qz-library-missing'`, `'connection-refused'`, `'timeout'`) |
| `SmartPrint.connect()` / `disconnect()` | Manual WebSocket control |
| `SmartPrint.isConnected()` | Boolean |

### Queue & Settings

| Method | Description |
|---|---|
| `SmartPrint.getQueue()` | Pending job objects |
| `SmartPrint.clearQueue()` | Cancel all pending jobs |
| `SmartPrint.retryJob(index)` | Retry one failed job |
| `SmartPrint.retryOffline()` | Flush the offline buffer |
| `SmartPrint.getSettings()` / `updateSettings({ defaultPrinter })` | Local settings |
| `SmartPrint.clearCache()` | Clear stored printer memory + offline buffer |

## Events Reference

```js
SmartPrint.on('connected', data => { /* … */ });
SmartPrint.off('connected', handler);   // remove a listener
```

| Event | Payload | Fires when |
|-------|---------|------------|
| `connected` | `{ printers: string[] }` | WebSocket connection established |
| `connection-failed` | `{ error }` | Tray unreachable |
| `disconnected` | — | Connection closed |
| `printers-loaded` | `{ printers: string[] }` | Printer list received from QZ Tray |
| `printer-saved` | `{ printer, scope }` | User selected/saved a printer |
| `job-queued` | `{ job }` | Job added to the queue |
| `job-processing` | `{ job }` | Job being sent to QZ Tray |
| `job-completed` | `{ job }` | Job sent successfully |
| `job-failed` | `{ job, error }` | Job failed (offline-buffered) |
| `fallback-print` | `{ job }` | Browser fallback printed the job |
| `queue-cleared` | — | Queue cleared |
| `cache-cleared` | — | Printer cache cleared |
| `settings-updated` | `{ settings }` | Settings changed |
| `ready` | `{ printers }` | SmartPrint fully initialized |
| `init-failed` | `{ reason }` | `qz-tray.min.js` not loaded before `smart-print.js` |

---

## Configuration Reference

`config/qz-tray.php` (published by `qz:install` / `--tag=qz-config`) — every key has a working default; none are mandatory:

```php
return [

    // Paths where the signing certificate and key live (never web-accessible)
    'cert_path' => storage_path('qz/digital-certificate.txt'),
    'key_path'  => storage_path('qz/private-key.pem'),
    'cert_ttl'  => 3600,                 // seconds the browser may cache the cert

    // Certificate generation defaults
    'certificate' => [
        'validity_days' => 7300,         // ~20 years
        'algorithm'     => 'sha256',
        'key_bits'      => 2048,
        'subject' => [
            'countryName'      => 'US',
            'organizationName' => 'My Company',
            'commonName'       => 'My App QZ Tray',
            'emailAddress'     => 'admin@myapp.com',
        ],
    ],

    // Auto-generate on first boot (dev convenience; use artisan commands in production)
    'auto_generate_cert' => env('QZ_AUTO_GENERATE_CERT', false),

    // Allow HTTP endpoint to (re)generate the cert — keep OFF in production
    'allow_public_cert_generate' => env('QZ_ALLOW_PUBLIC_CERT_GENERATE', false),

    // Default printer (optional — users pick via the switcher otherwise)
    'default_printer'           => env('QZ_DEFAULT_PRINTER'),
    'allow_printer_switch'      => true,
    'remember_printer_per_page' => true,    // memory per URL path
    'printer_cache_duration'    => 86400,   // 24h server-side cache

    // Which identity wins when several match: 'device' first is correct for
    // shared/kiosk workstations (the MACHINE picks the printer). Use
    // ['user', 'device', 'session'] if the printer should follow the person.
    'identity_priority' => ['device', 'user', 'session'],

    // Primary-key type for qz_print_jobs — set BEFORE first migrate.
    'id_type' => env('QZ_JOB_ID_TYPE', 'uuid'),          // 'uuid' | 'bigint'

    // 'v7' = time-ordered UUID (much better index locality than v4 for a
    // write-heavy table). Falls back to v4 on Laravel 10 automatically.
    'uuid_version' => env('QZ_UUID_VERSION', 'v7'),

    // QZ Tray WebSocket connection (client-side)
    'websocket' => [
        'host'    => env('QZ_WEBSOCKET_HOST', 'localhost'),
        'port'    => env('QZ_WEBSOCKET_PORT', 8181),
        'retries' => 1,
        'timeout' => 10,
    ],

    // Browser fallback when the tray is not running (classic layer)
    'fallback' => [
        'enabled'         => true,
        'open_in_new_tab' => true,
        'show_warning'    => true,
    ],

    // Keyboard shortcut that opens the printer switcher (Ctrl+Shift+P)
    'hotkey' => [
        'enabled'     => true,
        'combination' => 'ctrl+shift+p',
    ],

    // Route configuration — ADD 'auth' IN PRODUCTION
    'routes' => [
        'prefix'     => 'qz',
        'middleware' => ['web'],
        'throttle'   => '60,1',
    ],

    // Optional request-level logging to your Laravel log channel
    'logging' => [
        'enabled' => env('QZ_LOGGING_ENABLED', false),
        'channel' => env('QZ_LOGGING_CHANNEL', 'stack'),
        'level'   => env('QZ_LOGGING_LEVEL', 'info'),
    ],

    // Auto-tag every print/preference with a tenant id (see Multi-Tenant)
    // 'tenant_id_resolver' => fn ($request) => auth()->user()?->tenant_id,
];
```

**Browser-side config** (`window.QZ_CONFIG`, set in your layout before the scripts):

| Key | Default | Controls |
|---|---|---|
| `fallbackMode` | `'auto'` | Browser fallback strategy (see the [fallback table](#-fallback-engine--never-dead-end)) |
| `fetchMode` | `'auto'` | Page-fetch hydration: `'auto'` \| `'always'` \| `'never'` |
| `tenantId` / `projectId` | — | Tenant tag sent on every printer-memory/job call |
| `notify` | `false` | Toast when a job degrades to the browser |
| `onFallback(job)` | — | Hook invoked on every fallback print |
| `observeDom` | `false` | MutationObserver: auto-print elements injected after load (Turbo/Livewire/AJAX) fire too |
| `hotkey` | from config | Printer-switcher shortcut |
| `connectOnInit` | `false` | v1.5.5: connect at page load. Default **OFF** — the tray is contacted on the FIRST print or Ctrl+Shift+Q (no scans, no `/qz/printer` fetch, no console errors on machines without the tray) |
| `autoReconnect` | `false` | v1.5.5: background reconnect ladder (10s → 5min) for live status pages. Print-time probing makes it unnecessary for printing |
| `unavailableCooldownMs` | `60000` | v1.5.5: after one failed tray scan, prints skip the tray entirely (straight to the browser, no reload) for this long; the next print probes once more |
| `connectTimeoutMs` | `8000` | Cap on waiting for a (possibly hung) handshake before the print degrades to the browser |
| `printTimeoutMs` | `25000` | Cap on a silent QZ print before the browser fallback takes over |
| `connectionHotkey` | `ctrl+shift+q` | v1.5.5 tray connection-check shortcut (`{ enabled: false }` to disable, `combination` to rebind) |
| `connectRetryDelayMs` | `1500` | v1.5.5: nap between connect retries (0 = scan once, no retry nap) |

**Connection check (v1.5.5):** press **Ctrl+Shift+Q** anywhere for an instant
tray probe — toast + console shows `QZ Tray connected — N printers · using X`
or `QZ Tray NOT running — printing via the browser dialog`. It overrides any
cooldown, so it also doubles as "I just started the tray — reconnect now".
Programmatically: `SmartPrint.connectionCheck()`; current state:
`SmartPrint.status().unavailableForMs`.

## Artisan Commands

| Command | Description |
|---------|-------------|
| `qz:install` | Full install: publish everything + generate certificate |
| `qz:install --force` / `--no-cert` | Re-install overwriting files / skip certificate step |
| `qz:generate-certificate` | Generate the signing certificate |
| `qz:generate-certificate --force` | Force regenerate |
| `qz:generate-certificate --show` | Show certificate details after generation |
| `qz:generate-certificate --ca --domain=*.app.com,app.com` | Wildcard leaf **signed by your root CA** (zero-prompt Path B) |
| `qz:generate-ca` | Create your own free Root CA |
| `qz:certificate:export` | Export cert+key as password-protected `.pfx` (share across subdomains) |
| `qz:certificate:import --cert=… --key=…` | Import a CA fullchain (Let's Encrypt / cPanel / Cloudflare Origin) |
| `qz:certificate:import --pfx=… --password=…` | Import a shared `.pfx` pair |
| `qz:override:export [--show]` | Export the `override.crt` file clients deploy for signing trust |
| `qz:client-bundle [--zip] [--force] [--lna-domains=…]` | Build the Windows client deployment bundle (setup.bat, .ps1, certs, policies) |
| `qz:watch-certificate` | Auto re-import the watched cert pair after renewals (`QZ_WATCH_CERT`/`QZ_WATCH_KEY`) |
| `qz:doctor` | Health check: cert fingerprint/issuer/expiry, DB tables, config |
| `qz:prune-jobs` | Prune old `qz_print_jobs` rows (`--older-than`, `--status`, `--keep`, `--dry-run`) |
| `qz:prune-preferences` | Delete stale `qz_printer_preferences` rows (`--older-than=90` default, `--type=device|user|session`, `--dry-run`) |
| `qz:clear-cache` | Clear printer preferences for the requesting identity (`--session`, `--all`) |

**Schedule the pruning** (tables don't self-expire):

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('qz:prune-preferences --older-than=90')->weekly();
Schedule::command('qz:prune-jobs --older-than=30')->weekly();
```

## HTTP API Endpoints

All routes are prefixed `/qz` by default (`config/qz-tray.php → routes.prefix`). Add `auth` middleware in production.

### Security

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/certificate` | `qz.certificate` | Public signing certificate (plain text) |
| `GET` | `/qz/ca-certificate` | — | Your Root CA certificate (v1.4+) |
| `POST` | `/qz/sign` | `qz.sign` | Signs data with SHA-512 for QZ Tray verification |

### Status & Health

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/status` | `qz.status` | Full status: cert, key, endpoints, **trust posture** |
| `GET` | `/qz/health` | `qz.health` | Simple health check |
| `GET` | `/qz/test/connection` | `qz.test.connection` | API connectivity test |
| `POST` | `/qz/test-sign` | `qz.test-sign` | End-to-end signing pipeline test |

### Printer Management

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/printers` | `qz.printers` | Info endpoint (real list arrives via WebSocket) |
| `POST` | `/qz/printer` | `qz.printer.set` | Remember a printer per path + identity |
| `GET` | `/qz/printer/{path}` | `qz.printer.get` | Get remembered printer for a path |

**Identity scoping (v1.1+):** every request to `/qz/printer`, `/qz/print`, `/qz/jobs`, `/qz/clear-cache` is scoped by whichever identities are present, in `identity_priority` order — `device` (the `X-Device-Id` UUID `SmartPrint.getDeviceId()` sends automatically), `user` (authenticated), `session` (anonymous). Preferences are written for **every** identity present; there is no identity-less fallback, so two workstations can never read each other's printer.

### Print Jobs

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `POST` | `/qz/print` | `qz.print` | Accept and log a print job |
| `GET` | `/qz/jobs` | `qz.jobs` | List this workstation/user's active jobs (scoped) |
| `PATCH` | `/qz/jobs/{id}` | — | Update a job's status |
| `DELETE` | `/qz/jobs/{id}` | `qz.jobs.cancel` | Cancel a job by UUID |

### Cache, Setup & Wizard

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `POST` | `/qz/clear-cache` | `qz.clear-cache` | Clear printer cache |
| `GET` | `/qz/setup` | — | **Browser setup wizard** (client onboarding, v1.4+) |
| `POST` | `/qz/setup` | `qz.setup` | Setup info (cert/key status + endpoint URLs) |
| `POST` | `/qz/generate` | `qz.generate` | Generate cert via HTTP (disabled by default) |

### Client Bundle & Demo Pages

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/client-bundle` | — | Download the client deployment bundle (v1.4+) |
| `GET` | `/qz/test` | `qz.test` | Full QZ Tray demo (all print types) |
| `GET` | `/qz/smart` | `qz.smart` | SmartPrint interactive demo |
| `GET` | `/qz/test/pdf` | `qz.test.pdf` | Stream a generated test PDF |
| `GET` | `/qz/installer/{os}` | `qz.installer` | Installer info (windows/linux/macos) |

### Optional Stateless API (`routes/api.php`)

Enable with `QZ_API_ENABLED=true` — Sanctum-protected mirror of printers/print/jobs/status for server-to-server and mobile use.

---

## Multi-Tenant Support

Every table is tenant-aware, and identity is handled two ways:

**1. Native-typed tenant columns.** `tenant_id` (and `qz_print_jobs`' `user_id`/`user_type`) are `uuid` or `unsignedBigInteger` following `qz-tray.id_type` — one config to change, matching your project's primary-key convention:

```env
# .env — set BEFORE the first migrate
QZ_JOB_ID_TYPE=uuid        # or bigint
```

**2. Auto-tagging every request.** Set a resolver once and every print/preference call is tenant-tagged without touching call sites:

```php
// config/qz-tray.php
'tenant_id_resolver' => fn ($request) => auth()->user()?->tenant_id,

// or with stancl/tenancy:
'tenant_id_resolver' => fn ($request) => tenant('id'),
```

Explicit per-call tagging also works — either param name writes the same column:

```php
// POST /qz/print or POST /qz/printer
['tenant_id' => '482',                                   // id_type = bigint
 'project_id' => 'b2b1f6c0-3b3d-4c9a-9e2e-1a2b3c4d5e6f'] // id_type = uuid
```

Or page-wide from Blade, so `smart-print.js` sends it automatically:

```html
<script>window.QZ_CONFIG = { tenantId: '{{ auth()->user()?->tenant_id }}' };</script>
```

`GET /qz/jobs` narrows by tenant on top of its device/user scoping, and printer memory (`qz_printer_preferences`) is tenant-partitioned too.

> **Validation is strict:** a bigint tenant id on a `uuid`-configured install (or vice versa) is a validation error, not a silent write.

**Filtering your own reports:**

```php
$jobs = DB::table('qz_print_jobs')
    ->where('tenant_id', auth()->user()->tenant_id)
    ->where('status', 'completed')
    ->orderByDesc('created_at')
    ->paginate(20);
```

## Database Schema

Two optional tables — the package works without them (logging and server-synced printer memory simply no-op), but you want both for anything beyond a demo.

### `qz_print_jobs`

Written automatically by `POST /qz/print` on every successful print. You never write to it manually.

| Column | Type | Description |
|--------|------|-------------|
| `id` | `uuid` or `bigint` | PK — `id_type` config. UUIDs are non-guessable and safe to expose to the client |
| `tenant_id` | `uuid`/`bigint`, nullable | Type follows `id_type` |
| `user_id` / `user_type` | morph, nullable | Typed by `id_type` (`nullableUuidMorphs()` / `nullableMorphs()`) |
| `device_id` | uuid, nullable | Workstation/browser that submitted the job |
| `printer_name` | string | Printer used |
| `document_url` | string, nullable | What was printed |
| `document_type` | string | `pdf`, `html`, `image`, `zpl`, `escpos`, `raw` |
| `copies` | int | Copies requested |
| `status` | string | `pending` → `processing` → `completed` / `failed` / `cancelled` |
| `error_message` | text, nullable | Populated on failure |
| `metadata` | JSON, nullable | Extra data |
| `processed_at` | timestamp, nullable | Completion time |

### `qz_printer_preferences`

Server-side mirror of printer memory — one row per `(tenant_id, identity_type, identity_value, path)`:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Auto-increment PK |
| `tenant_id` | `uuid`/`bigint`, nullable | Type follows `id_type` |
| `identity_type` | string | `device`, `user`, or `session` |
| `identity_value` | string | Device UUID / user id / session id |
| `path` | string | URL path the printer was remembered for |
| `printer_name` | string | The remembered printer |

Rows are not pruned automatically — wire `qz:prune-preferences` into your scheduler (see [Artisan Commands](#artisan-commands)).

## Security

### Production Hardening Checklist

```php
// 1. Protect all /qz/* routes with auth:
// config/qz-tray.php
'routes' => [
    'prefix'     => 'qz',
    'middleware' => ['web', 'auth'],      // ← add in production
    'throttle'   => '60,1',               // signing endpoint rate limit
],
// extra middleware is fine too: ['web', 'auth', 'verified', 'role:printer']
```

```bash
# 2. Verify the private key is NOT web-accessible (expect 404):
curl -I https://yourapp.com/storage/qz/private-key.pem
curl -I https://yourapp.com/storage/qz/ca/qz-root-ca.key
```

```php
// 3. Never enable in production:
'allow_public_cert_generate' => false,
```

```bash
# 4. Keep debug bars away from /qz/* (a debug payload in /qz/sign responses
#    corrupts signature verification and test PDFs):
# config/debugbar.php → 'except' => ['qz/*'] — or disable debugbar in production.
```

### How the Trust Chain Works

- The **private key** (`storage/qz/private-key.pem`) signs requests server-side. It never leaves the server and `storage/` is not web-served by default.
- The **public certificate** (`storage/qz/digital-certificate.txt`) is served at `/qz/certificate`; QZ Tray uses it to verify that print requests genuinely came from your app.
- Every `POST` from SmartPrint carries your CSRF token automatically — no `VerifyCsrfToken` exclusions needed (do **not** add `qz/sign` to `$except`).
- With [Zero-Prompt Path B](#path-b--your-own-root-ca--overridecrt-recommended-when-you-control-the-clients), clients additionally trust your own root CA — which you control and can rotate.

## Environment Variables

**None are required** — every variable has a working default. Add only the ones you want to override.

| Variable | Default | Controls |
|---|---|---|
| `QZ_DEFAULT_PRINTER` | *(none)* | Pre-select a printer instead of prompting on first print |
| `QZ_WEBSOCKET_HOST` | `localhost` | Host QZ Tray's WebSocket listens on |
| `QZ_WEBSOCKET_PORT` | `8181` | Port QZ Tray's WebSocket listens on |
| `QZ_AUTO_GENERATE_CERT` | `false` | Auto-generate a self-signed cert on boot — dev only |
| `QZ_ALLOW_PUBLIC_CERT_GENERATE` | `false` | Allow cert generation via HTTP — leave `false` |
| `QZ_LOGGING_ENABLED` | `false` | Write every `POST /qz/print` to the Laravel log |
| `QZ_LOGGING_CHANNEL` | `stack` | Log channel, if enabled |
| `QZ_LOGGING_LEVEL` | `info` | Log level, if enabled |
| `QZ_JOB_ID_TYPE` | `uuid` | `qz_print_jobs` PK type — set **before** first migrate |
| `QZ_UUID_VERSION` | `v7` | `v7` (time-ordered) or `v4` (random) for UUID PKs |
| `QZ_API_ENABLED` | `false` | Expose the Sanctum-protected stateless API routes |
| `QZ_CERT_PATH` / `QZ_KEY_PATH` | storage paths | Point several vhosts at one shared keypair |
| `QZ_WATCH_CERT` / `QZ_WATCH_KEY` | *(none)* | Files `qz:watch-certificate` re-imports after renewals |

Copy-paste block (all defaults — edit only what you change):

```env
QZ_DEFAULT_PRINTER=
QZ_WEBSOCKET_HOST=localhost
QZ_WEBSOCKET_PORT=8181
QZ_AUTO_GENERATE_CERT=false
QZ_ALLOW_PUBLIC_CERT_GENERATE=false
QZ_LOGGING_ENABLED=false
QZ_LOGGING_CHANNEL=stack
QZ_LOGGING_LEVEL=info
QZ_JOB_ID_TYPE=uuid
QZ_UUID_VERSION=v7
QZ_API_ENABLED=false
```

Realistic production override:

```env
QZ_DEFAULT_PRINTER="HP LaserJet M404"
QZ_LOGGING_ENABLED=true
QZ_LOGGING_CHANNEL=daily
```

---

## Troubleshooting

### Fast Diagnosis

```bash
php artisan qz:doctor            # server-side health: cert, key, DB, config
# GET /qz/status                 # HTTP posture incl. trust reporting
# GET /qz/smart                  # client-side: WebSocket + printer list + live print
```

### Symptom → Cause → Fix

| Symptom | Likely cause | Fix |
|---|---|---|
| "Certificate not found" | Cert missing or `storage/qz` unwritable | `php artisan qz:generate-certificate` and `chmod -R 775 storage/qz` |
| *"Could not connect — is QZ Tray running?"* | Tray not installed/started, or page served from `file://` | Install from [qz.io/download](https://qz.io/download), check the system-tray icon, port `8181`, serve over `http(s)://` |
| QZ Tray error `Cannot parse … End-of-File` on a URL print | The URL answered **HTML, not a PDF** — almost always an auth-protected route (QZ has no session) | Let hydration do its job: use `printUrl()` with `fetchMode: 'auto'` (default). For cross-origin routes use `{ fetch: true }` or `fetchMode: 'always'` |
| The call resolves `reason: 'unexpected-response'` | Same as above — hydration caught a login/404 page where a document was expected | Working as intended: check the route's auth/session, then retry |
| `419 CSRF token mismatch` on `/qz/sign` | Missing CSRF meta tag | Add `<meta name="csrf-token" content="{{ csrf_token() }}">` to `<head>` — do **not** exclude `qz/*` from CSRF |
| Print jobs produce garbled/failed output in dev | Laravel Debugbar injects HTML into responses | Disable Debugbar in production or add `'except' => ['qz/*']` to `config/debugbar.php` |
| "Untrusted website" prompt returns **every** print | The user pressed plain **Allow** (session-only) | Press **Always Allow** once — or eliminate it entirely with [Zero-Prompt Path A/B](#zero-prompt-trust--production-deployment) |
| Prompt appears per subdomain | Each vhost generated its own self-signed cert (fingerprint-based trust) | Share one cert pair ([Path C](#path-c--shared-self-signed-keypair-smallest-setups)) or go zero-prompt (Path A/B) |
| Chrome/Edge blocks `localhost:8181` | Chromium Local Network Access restrictions | Include `--lna-domains='[*.]yourdomain.com'` when building the client bundle (Path B) |
| Client downloaded `.zip` is invalid | Corrupting layer between server and disk | Use the package's own `qz:client-bundle --zip` (pure-PHP ZipBuilder, atomic write). Verify server-side: `unzip -t storage/qz/client-bundle/*.zip` |
| Printer list empty | No WebSocket / no printers in OS | Confirm tray running + connected on `/qz/smart`; add the printer in the **OS** first — QZ Tray lists whatever the OS lists |
| Works in dev, fails on HTTPS | Cert chain problems | Site must be `https://`, `/qz/certificate` must load cleanly, QZ Tray 2.x installed |
| `openssl_sign failed` | Corrupted private key | `php artisan qz:generate-certificate --force` |
| `init-failed` event fires | `qz-tray.min.js` loaded after `smart-print.js` (or missing) | Load order: `qz-tray.min.js` **then** `smart-print.js` |
| `.smart-print` button does nothing | Nothing to print on the element | Add `data-qz-url` / `data-qz-html` / `data-qz-element` / `data-qz-urls`, or `onclick="printUrl(…)"` — the console hint lists the options |

### Clean-Slate Reset

```bash
php artisan qz:clear-cache --all
php artisan qz:prune-preferences --older-than=0
php artisan cache:clear && php artisan config:clear
```

## File Structure

Published to your Laravel app by `qz:install`:

```
your-laravel-app/
├── config/
│   └── qz-tray.php                     ← Main configuration
├── storage/
│   └── qz/
│       ├── digital-certificate.txt     ← Public certificate (auto-generated)
│       ├── private-key.pem             ← Private key — never expose this
│       └── client-bundle/              ← Windows deployment bundle (qz:client-bundle)
├── database/migrations/
│   ├── …_create_qz_print_jobs_table.php
│   ├── …_create_qz_printer_preferences_table.php
│   └── …_add_client_job_id_to_qz_print_jobs_table.php
├── resources/views/vendor/qz-tray/
│   ├── default.blade.php               ← Full QZ demo        (/qz/test)
│   ├── smart.blade.php                 ← SmartPrint demo     (/qz/smart)
│   ├── wizard.blade.php                ← Setup wizard        (/qz/setup)
│   └── example.blade.php               ← Usage examples
└── public/vendor/qz-tray/
    ├── js/
    │   ├── qz-tray.min.js              ← QZ Tray WebSocket library (self-hosted, 2.2.6)
    │   ├── smart-print.js              ← SmartPrint smart client ⭐
    │   ├── printer-status.js           ← Status widget
    │   ├── printer-switcher.js         ← Printer switcher widget
    │   ├── sample/
    │   │   └── smart-actions.lab.example.js  ← copy-paste starter for your app
    │   └── adapters/
    │       ├── zpl.js                  ← ZPL label builder
    │       ├── escpos.js               ← ESC/POS receipt builder
    │       └── raw-print.js            ← Raw print helper
    ├── css/ … and fonts/               ← Bootstrap + Font Awesome for the demo pages
    └── installers/                     ← QZ Tray desktop installers (if published)
```

Package source (`vendor/bitdreamit/laravel-qz-tray/`): `src/QzTrayServiceProvider.php`, `src/Http/Controllers/QzSecurityController.php`, `src/Support/{CertKit,ZipBuilder}.php`, `src/Console/Commands/*` (all artisan commands), `resources/js/smart-print.js` (the smart client), `docs/zero-prompt.md`, `docs/multi-domain.md`.

## Upgrade Guide

### From v1.4.x to v1.5.0

v1.5.0 is a **drop-in client upgrade** — your existing buttons, events and PHP code keep working unchanged.

```bash
composer update bitdreamit/laravel-qz-tray
php artisan optimize:clear
php artisan vendor:publish --tag=qz-assets --force   # the new smart-print.js
```

**What you get automatically, with zero code changes:**

- `printUrl` / `printUrls` / `printPdf` / `printPdfs` / `printImage` / `printImages` / `printHtml` / `printElement` / `printPage` / `printAnyUrl(s)` — built in as `SmartPrint` methods, window globals and `data-qz-action` targets.
- `class="smart-print"` binding on any button/link, plus `onclick="printUrl(…)"` routing.
- Auth-protected mPDF routes work (page-fetch hydration) — no signed URLs needed.
- Automatic browser fallback everywhere.

**Optional cleanup:** remove any custom `define()` blocks that only aliased the generic names — the built-ins now do it. Custom presets (printer/profile baked in) still win over the built-ins, so keep those.

### Legacy (v0.x → v1.0)

```bash
composer update bitdreamit/laravel-qz-tray
php artisan vendor:publish --tag=qz-assets --force
php artisan vendor:publish --tag=qz-config --force
php artisan qz:generate-certificate --force
php artisan migrate
```

Replace old `data-smart-print` attributes with `data-qz-print` (both still work; `data-qz-print` is preferred).

## FAQ

**Q: Does this package require a paid QZ Tray license?**
A: No. The certificate toolkit this package ships (root CA, wildcard leaf, client bundle) is 100% free and self-hosted. QZ Tray Community Edition is free for internal/self-hosted use; a commercial license from QZ Industries is only needed if you redistribute QZ Tray itself. See [qz.io/pricing](https://qz.io/pricing).

**Q: Do mPDF `->stream()` / `->download()` URLs work?**
A: Yes — both, even behind `auth` middleware. The page fetches same-origin URLs with session cookies and hands QZ the bytes; `Content-Disposition` is ignored. Requirements: GET route, same origin, logged-in session. See [Printing mPDF PDFs behind auth](#printing-mpdf-pdfs-behind-auth-middleware).

**Q: Does this work on mobile devices?**
A: QZ Tray is a desktop application — mobile browsers cannot reach `localhost:8181`. The fallback engine prints via the normal mobile browser dialog instead, so nothing breaks.

**Q: Can multiple users print to different printers at the same time?**
A: Yes. Each browser tab holds its own WebSocket to the tray on that machine; printer memory is scoped per device/user/session/path/tenant.

**Q: What happens if QZ Tray is not installed?**
A: Nothing breaks. The same call silently prints via the browser fallback engine (hidden iframe by default), or follows your configured `fallbackMode`. Listen for `fallback-print` if you want to show a hint or a download link.

**Q: Can I use this with React, Vue, or Livewire?**
A: Yes — `SmartPrint` is a plain `window` object, and the `class="smart-print"` binding is framework-agnostic markup. See [Framework Integration](#-framework-integration).

**Q: Can I print to network printers?**
A: Yes — QZ Tray lists whatever the **operating system** has registered. Add the printer in the OS (USB driver, TCP/IP port, or a raw `socket://` CUPS queue / Standard TCP/IP port for ZPL/ESC-POS) and it appears in `SmartPrint.getPrinters()` with its exact OS name. QZ Industries has step-by-step guides for [Windows raw printers](https://qz.io/docs/setting-up-a-raw-printer-in-windows) and [macOS raw printers](https://qz.io/docs/setting-up-a-raw-printer-in-osx).

**Q: Is the private key secure?**
A: Yes — it lives in `storage/qz/`, which Laravel does not web-serve, is used only server-side for signing, and is never sent to the browser. Verify yourself: `curl -I https://yourapp.com/storage/qz/private-key.pem` → 404.

**Q: The certificate is valid for 20 years — do I ever renew?**
A: Only if you want to. `qz:generate-certificate --force` re-issues any time; with a root CA (Path B) re-issuing the leaf never requires touching clients.

**Q: Does every browser need a special extension?**
A: No extensions. QZ Tray runs as a native desktop app and exposes a local WebSocket the browser talks to directly.

## Support & License

- **Issues:** [github.com/bitdreamit/laravel-qz-tray/issues](https://github.com/bitdreamit/laravel-qz-tray/issues)
- **Email:** info@bitdreamit.com
- **QZ Tray API docs:** [qz.io/api](https://qz.io/api)
- **Deep-dive guides:** [docs/zero-prompt.md](docs/zero-prompt.md) · [docs/multi-domain.md](docs/multi-domain.md)

MIT License — see [LICENSE](LICENSE).

<p align="center">Made with ❤️ by <a href="https://bitdreamit.com">Bit Dream IT</a></p>
