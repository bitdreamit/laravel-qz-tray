# Laravel QZ Tray

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/QZ%20Tray-2.2.6-0078D4?style=for-the-badge" alt="QZ Tray">
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License">
</p>

> **Enterprise silent printing for Laravel.** Print PDFs, ZPL labels, ESC/POS receipts, and raw commands directly from your browser — no print dialog, no pop-ups — using QZ Tray's WebSocket bridge.

---

## Quick Start

For the impatient — five commands to a working test print. Every step is explained in full further down; this is just the fast path.

```bash
composer require bitdreamit/laravel-qz-tray
php artisan qz:install          # publishes config, migrations, views, JS/CSS assets, generates your cert
php artisan migrate             # creates qz_print_jobs + qz_printer_preferences
```

Add the CSRF meta tag and two `<script>` tags to your layout (see [Step 4](#step-4--add-scripts-to-your-layout) for the exact snippet), install [QZ Tray](https://qz.io/download) on the machine that will physically print, then visit:

```
/qz/smart
```

That page shows live connection status, lets you pick a printer, and print a real test job. If it prints, you're done — jump to [Frontend Usage](#frontend-usage--smartprint-js) for how to wire it into your own pages.

---

## Table of Contents

- [Quick Start](#quick-start)
- [What is This?](#what-is-this)
- [Requirements](#requirements)
- [Installation — Step by Step](#installation--step-by-step)
  - [Step 1 — Install via Composer](#step-1--install-via-composer)
  - [Step 2 — Run the Installer Command](#step-2--run-the-installer-command)
  - [Step 3 — Run Migrations](#step-3--run-migrations)
  - [Step 4 — Add Scripts to Your Layout](#step-4--add-scripts-to-your-layout)
  - [Step 5 — Install QZ Tray on Client Machines](#step-5--install-qz-tray-on-client-machines)
  - [Step 6 — Verify Everything Works](#step-6--verify-everything-works)
- [Publishing Assets — Manual Control](#publishing-assets--manual-control)
- [Configuration Reference](#configuration-reference)
- [Certificate Management](#certificate-management)
- [Artisan Commands](#artisan-commands)
- [All Available Routes / API Endpoints](#all-available-routes--api-endpoints)
- [Frontend Usage — SmartPrint JS](#frontend-usage--smartprint-js)
  - [Method 1 — HTML Data Attributes (Zero JS)](#method-1--html-data-attributes-zero-js)
  - [Method 2 — JavaScript API](#method-2--javascript-api)
  - [Method 3 — Global Shorthand Functions](#method-3--global-shorthand-functions)
  - [Print Types Reference](#print-types-reference)
  - [All Data Attributes Reference](#all-data-attributes-reference)
  - [Full SmartPrint API Reference](#full-smartprint-api-reference)
  - [Events Reference](#events-reference)
- [Printing Use Cases](#printing-use-cases)
  - [Print a PDF Invoice](#print-a-pdf-invoice)
  - [Print a ZPL Label (Zebra)](#print-a-zpl-label-zebra)
  - [Print an ESC/POS Receipt (Thermal)](#print-an-escpos-receipt-thermal)
  - [Auto-Print on Page Load](#auto-print-on-page-load)
  - [Print with Copies and Delay](#print-with-copies-and-delay)
  - [Printer Switcher Modal](#printer-switcher-modal)
  - [Print Queue with Retry](#print-queue-with-retry)
  - [Offline Queue (Print When Reconnected)](#offline-queue-print-when-reconnected)
  - [Listen to Print Events](#listen-to-print-events)
  - [Using the ZPL Adapter](#using-the-zpl-adapter)
  - [Using the ESC/POS Adapter](#using-the-escpos-adapter)
- [Laravel Backend Printing (Server-Side)](#laravel-backend-printing-server-side)
- [Database — Print Job Logging](#database--print-job-logging)
- [Multi-Tenant Support](#multi-tenant-support)
- [Security](#security)
- [Environment Variables Reference](#environment-variables-reference)
- [Troubleshooting](#troubleshooting)
- [File Structure](#file-structure)
- [Upgrade Guide](#upgrade-guide)
- [FAQ](#faq)

---

## What is This?

Standard web printing always shows a dialog box. **Laravel QZ Tray** eliminates that entirely.

```
Browser  ──HTTP──►  Laravel App  ──WebSocket──►  QZ Tray (desktop)  ──►  Printer
```

**How it works:**
1. **QZ Tray** is a small Java application running on each client machine. It opens a local WebSocket server on port `8181`.
2. Your Laravel app provides a **signed certificate** (at `/qz/certificate`) so QZ Tray trusts your website.
3. **SmartPrint.js** connects to QZ Tray via WebSocket and sends print jobs silently.
4. The print job goes directly to the physical printer — no dialog, no confirmation.

**Supported print types:**
| Type | Description | Example Printers |
|------|-------------|-----------------|
| `pdf` | PDF documents | Any printer |
| `html` | HTML content | Any printer |
| `zpl` | Zebra Programming Language | Zebra ZD420, ZT230 |
| `escpos` | ESC/POS thermal commands | Epson TM, Star Micronics |
| `raw` | Raw byte commands | Any raw-capable printer |

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.1 or higher |
| Laravel | 10, 11,12 or 13  |
| PHP extension | `ext-openssl` (for certificate generation) |
| QZ Tray (client) | 2.x — installed on each machine that prints |

> **Note:** QZ Tray must be installed on every **client machine** (the computer connected to the printer). It does NOT need to be on your server.

---

## Installation — Step by Step

### Step 1 — Install via Composer

```bash
composer require bitdreamit/laravel-qz-tray
```

Laravel auto-discovers the service provider. Nothing extra needed.

---

### Step 2 — Run the Installer Command

```bash
php artisan qz:install
```

This single command does everything:

- Publishes `config/qz-tray.php`
- Publishes database migrations
- Publishes Blade views to `resources/views/vendor/qz-tray/`
- Publishes JavaScript and CSS assets to `public/vendor/qz-tray/`
- Generates your SSL certificate automatically

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

> **Force reinstall:** Add `--force` to overwrite existing files:
> ```bash
> php artisan qz:install --force
> ```

---

### Step 3 — Run Migrations

```bash
php artisan migrate
```

This creates the `qz_print_jobs` table for optional print job logging.

---

### Step 4 — Add Scripts to Your Layout

Add these two lines to your main Blade layout (e.g. `resources/views/layouts/app.blade.php`), just before `</body>`:

```html
<!DOCTYPE html>
<html>
<head>
    {{-- Required: CSRF token for signing requests --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
    {{-- Your content here --}}

    {{-- Step 1: QZ Tray WebSocket library (CDN) --}}
    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.6/qz-tray.min.js"></script>

    {{-- Step 2: SmartPrint library (published asset) --}}
    <script src="{{ asset('vendor/qz-tray/js/smart-print.js') }}"></script>
</body>
</html>
```

> **Important:** The `<meta name="csrf-token">` tag is required. SmartPrint uses it to sign print requests.

---

### Step 5 — Install QZ Tray on Client Machines

Each computer that will physically print needs **QZ Tray** installed.

**Download links by OS:**
```
Windows:  /qz/installer/windows  (or https://qz.io/download)
Linux:    /qz/installer/linux
macOS:    /qz/installer/macos
```

Or link directly in your app:

```html
<a href="https://qz.io/download" target="_blank">
    Download QZ Tray
</a>
```

After installing, QZ Tray starts automatically with Windows/macOS and runs in the system tray. No configuration needed on the client side.

---

### Step 6 — Verify Everything Works

1. **Check status endpoint:**
   ```
   GET /qz/status
   ```
   Should return:
   ```json
   {
     "success": true,
     "status": "operational",
     "certificate": "present",
     "private_key": "present"
   }
   ```

2. **Open the interactive test page:**
   ```
   /qz/test
   ```
   This is the official QZ Tray demo — you can test all print types here.

3. **Open the SmartPrint demo page:**
   ```
   /qz/smart
   ```
   Interactive page showing connection status, printer list, and live print testing.

4. **Test the signing pipeline:**
   ```
   POST /qz/test-sign
   ```
   Should return `"message": "Signing works correctly"`.

---

## Publishing Assets — Manual Control

`php artisan qz:install` (Step 2 above) runs every publish group at once plus generates a certificate — the right choice for a first install. Reach for the individual `vendor:publish` commands below when you want more control: republishing only the config after an update, keeping generated views out of a CI pipeline, or a Docker build step that shouldn't touch `storage/`.

| Tag | Command | Publishes | Destination |
|-----|---------|-----------|-------------|
| `qz-config` | `php artisan vendor:publish --tag=qz-config` | `config/qz-tray.php` | `config/qz-tray.php` |
| `qz-migrations` | `php artisan vendor:publish --tag=qz-migrations` | `qz_print_jobs` + `qz_printer_preferences` migrations | `database/migrations/` |
| `qz-blade` | `php artisan vendor:publish --tag=qz-blade` | Demo/test Blade views (`smart.blade.php`, `default.blade.php`, `example.blade.php`) | `resources/views/vendor/qz-tray/` |
| `qz-assets` | `php artisan vendor:publish --tag=qz-assets` | `smart-print.js`, `printer-switcher.js`, `printer-status.js`, adapters, the vendored `qz-tray.js`, CSS, fonts | `public/vendor/qz-tray/` |
| `qz-installers` | `php artisan vendor:publish --tag=qz-installers` | QZ Tray desktop installers (Windows/macOS/Linux), if bundled | `public/vendor/qz-tray/installers/` |

Publish everything at once without the installer command's certificate-generation step:

```bash
php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider"
```

Add `--force` to any of the above to overwrite files you've already published (e.g. after upgrading the package and wanting the latest `smart-print.js`):

```bash
php artisan vendor:publish --tag=qz-assets --force
```

> **After publishing `qz-assets`, re-run `php artisan migrate` if you also re-published `qz-migrations`** — publishing only copies the migration file into your app; it doesn't run it.

---

## Configuration Reference

After publishing, edit `config/qz-tray.php`:

```php
return [

    // Paths where the auto-generated certificate and key are stored
    'cert_path' => storage_path('qz/digital-certificate.txt'),
    'key_path'  => storage_path('qz/private-key.pem'),
    'cert_ttl'  => 3600, // seconds the browser may cache the cert

    // Certificate generation settings
    'certificate' => [
        'validity_days' => 7300,      // ~20 years
        'algorithm'     => 'sha256',
        'key_bits'      => 2048,
        'subject' => [
            'countryName'      => 'BD',
            'organizationName' => 'Bit Dream IT',
            'commonName'       => 'Laravel QZ Tray',
            'emailAddress'     => 'info@bitdreamit.com',
        ],
    ],

    // Auto-generate on first boot (safe for development, use artisan in production)
    'auto_generate_cert' => env('QZ_AUTO_GENERATE_CERT', false),

    // Allow HTTP endpoint to generate cert (disabled by default — security risk)
    'allow_public_cert_generate' => env('QZ_ALLOW_PUBLIC_CERT_GENERATE', false),

    // Default printer name (optional — users can pick via modal)
    'default_printer'           => env('QZ_DEFAULT_PRINTER'),
    'allow_printer_switch'      => true,
    'remember_printer_per_page' => true,   // Remember per URL path
    'printer_cache_duration'    => 86400,  // seconds (24 hours)

    // v1.1.0+: which identity wins when a request matches more than one
    // (device UUID, authenticated user, session)? 'device' first is correct
    // for shared/kiosk workstations where the physical machine — not who's
    // logged in — determines the printer. Use ['user', 'device', 'session']
    // if printer choice should follow a person between machines instead.
    'identity_priority' => ['device', 'user', 'session'],

    // v1.1.1+: primary key type for qz_print_jobs. Read at migration time —
    // set BEFORE first `php artisan migrate`. 'uuid' (default): id is a
    // uuid, safe to hand straight to the client (used as-is by
    // GET /qz/jobs and DELETE /qz/jobs/{id}). 'bigint': plain
    // auto-increment id.
    'id_type' => env('QZ_JOB_ID_TYPE', 'uuid'),

    // v1.1.3+: 'v7' (default) — time-ordered uuid, much better DB index
    // locality than v4 for a write-heavy table (v4 scatters inserts
    // randomly across the B-tree; v7's leading timestamp keeps them
    // appending near the end). Falls back to v4 automatically on Laravel
    // 10.x (no native Str::uuid7()) or if generation fails for any reason.
    'uuid_version' => env('QZ_UUID_VERSION', 'v7'),

    // QZ Tray WebSocket connection
    'websocket' => [
        'host'    => env('QZ_WEBSOCKET_HOST', 'localhost'),
        'port'    => env('QZ_WEBSOCKET_PORT', 8181),  // QZ Tray default
        'retries' => 1,
        'timeout' => 10,
    ],

    // Browser fallback when QZ Tray is not running
    'fallback' => [
        'enabled'         => true,   // Open browser print dialog as fallback
        'open_in_new_tab' => true,
        'show_warning'    => true,
    ],

    // Keyboard shortcut to open printer selector
    'hotkey' => [
        'enabled'     => true,
        'combination' => 'ctrl+shift+p',
    ],

    // Route configuration
    'routes' => [
        'prefix'     => 'qz',        // All routes: /qz/...
        'middleware' => ['web'],      // Add 'auth' here to protect routes
        'throttle'   => '60,1',
    ],

    // Print job logging
    'logging' => [
        'enabled' => env('QZ_LOGGING_ENABLED', false),
        'channel' => env('QZ_LOGGING_CHANNEL', 'stack'),
        'level'   => env('QZ_LOGGING_LEVEL', 'info'),
    ],

];
```

**Protect routes with authentication** (recommended for production):

```php
// config/qz-tray.php
'routes' => [
    'prefix'     => 'qz',
    'middleware' => ['web', 'auth'],  // Require login
],
```

---

## Certificate Management

The certificate allows QZ Tray to trust your website. It is a self-signed SSL certificate generated on your server — **only the public certificate is sent to the browser; the private key never leaves your server.**

### Generate a new certificate

```bash
php artisan qz:generate-certificate
```

### Force regenerate (replace existing)

```bash
php artisan qz:generate-certificate --force
```

### Show certificate details after generation

```bash
php artisan qz:generate-certificate --show
```

**Example output:**
```
🔐 Generating QZ Tray certificate...
  Generating private key...
  Creating certificate signing request...
✅ Certificate generated successfully!
  📄 Certificate: /var/www/storage/qz/digital-certificate.txt
  🔑 Private key:  /var/www/storage/qz/private-key.pem
  ⏳ Validity: 7300 days (20 years)

📋 Certificate Details:
  Subject:     /C=US/O=My Company/CN=My App QZ Tray
  Valid From:  2025-01-01 00:00:00
  Valid Until: 2045-01-01 00:00:00
  Algorithm:   RSA
```

### Customize certificate subject

Edit `config/qz-tray.php`:

```php
'certificate' => [
    'validity_days' => 7300,
    'algorithm'     => 'sha256',
    'key_bits'      => 2048,
    'subject' => [
        'countryName'            => 'GB',         // ISO country code
        'stateOrProvinceName'    => 'London',
        'localityName'           => 'London',
        'organizationName'       => 'Acme Corp',
        'organizationalUnitName' => 'IT Department',
        'commonName'             => 'Acme Print Service',
        'emailAddress'           => 'it@acme.com',
    ],
],
```

Then regenerate:

```bash
php artisan qz:generate-certificate --force
```

### Certificate file locations

| File | Path | Purpose |
|------|------|---------|
| Public certificate | `storage/qz/digital-certificate.txt` | Sent to browser at `/qz/certificate` |
| Private key | `storage/qz/private-key.pem` | Signs requests on server — **never exposed** |

> **Security:** The `storage/qz/` directory should not be web-accessible. Laravel's `storage/` folder is not served by default — this is correct.

---

## Artisan Commands

| Command | Description |
|---------|-------------|
| `php artisan qz:install` | Full install: publish everything + generate cert |
| `php artisan qz:install --force` | Re-install, overwrite existing files |
| `php artisan qz:install --no-cert` | Install without generating a certificate |
| `php artisan qz:generate-certificate` | Generate SSL certificate |
| `php artisan qz:generate-certificate --force` | Force regenerate certificate |
| `php artisan qz:generate-certificate --show` | Show certificate details |
| `php artisan qz:clear-cache` | Clear stored printer preferences (`qz_printer_preferences` table) for the requesting identity |
| `php artisan qz:clear-cache --session` | Also clear session-scoped printer data |
| `php artisan qz:clear-cache --all` | Clear everything, including session data |
| `php artisan qz:prune-preferences` | Delete `qz_printer_preferences` rows not updated in the last 90 days (default) |
| `php artisan qz:prune-preferences --older-than=30` | Custom age threshold, in days |
| `php artisan qz:prune-preferences --type=session` | Only prune one identity type (`device`, `user`, or `session`) |
| `php artisan qz:prune-preferences --dry-run` | Preview what would be deleted, without deleting it |

`qz:prune-preferences` isn't scheduled automatically — the `qz_printer_preferences` table (unlike the old Cache-backed printer memory) doesn't expire on its own. Wire it into your scheduler if row growth matters for your install:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php's schedule() method
Schedule::command('qz:prune-preferences --older-than=90')->weekly();
```

---

## All Available Routes / API Endpoints

All routes are prefixed with `/qz` by default (configurable).

### Security

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/certificate` | `qz.certificate` | Returns the public certificate (plain text) |
| `POST` | `/qz/sign` | `qz.sign` | Signs data with SHA512 for QZ Tray verification |

### Status & Health

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/status` | `qz.status` | Full status: cert, key, endpoints |
| `GET` | `/qz/health` | `qz.health` | Simple health check |
| `GET` | `/qz/test/connection` | `qz.test.connection` | Test API connectivity |
| `POST` | `/qz/test-sign` | `qz.test-sign` | Test signing pipeline end-to-end |

### Printer Management

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/printers` | `qz.printers` | Info endpoint (actual list comes via WebSocket) |
| `POST` | `/qz/printer` | `qz.printer.set` | Remember a printer for a URL path |
| `GET` | `/qz/printer/{path}` | `qz.printer.get` | Get remembered printer for a URL path |

**Device identity (v1.1.0+):** every request to `/qz/printer`, `/qz/print`, `/qz/jobs`, and `/qz/clear-cache` is scoped by whichever of these identities is present, in the order set by `qz-tray.identity_priority` (default `device → user → session`):

- **`device`** — send an `X-Device-Id: <uuid>` header (or `device_id` body/query param). `SmartPrint.getDeviceId()` returns the UUID `smart-print.js` already generates and persists per browser/workstation — use the same value if you call these endpoints yourself.
- **`user`** — the authenticated user (`auth()->user()`), when the route runs behind an auth middleware.
- **`session`** — anonymous fallback, isolated per Laravel session.

A request can match more than one identity at once (e.g. a logged-in user on a device-identified kiosk); `POST /qz/printer` writes a preference row for every identity present, so switching `identity_priority` later doesn't lose data. There is **no** unscoped, identity-less fallback — two different users/workstations can never read each other's stored printer.

```js
// smart-print.js already does this for you on every fetch; shown here for
// direct API use (e.g. server-to-server or a custom admin dashboard):
fetch('/qz/printer', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        'X-Device-Id':  SmartPrint.getDeviceId(),
    },
    body: JSON.stringify({ printer: 'Label Printer', path: '/orders/5' }),
});
```

### Print Jobs

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `POST` | `/qz/print` | `qz.print` | Accept and log a print job |
| `GET` | `/qz/jobs` | `qz.jobs` | List **this workstation/user's** active print jobs (scoped, see above) |
| `DELETE` | `/qz/jobs/{id}` | `qz.jobs.cancel` | Cancel a print job by its UUID |

### Cache & Setup

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `POST` | `/qz/clear-cache` | `qz.clear-cache` | Clear printer cache |
| `POST` | `/qz/setup` | `qz.setup` | Setup info (cert/key status + endpoint URLs) |
| `POST` | `/qz/generate` | `qz.generate` | Generate cert via HTTP (disabled by default) |

### Demo & Test Pages

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/test` | `qz.test` | Full QZ Tray demo page (all print types) |
| `GET` | `/qz/smart` | `qz.smart` | SmartPrint interactive demo page |
| `GET` | `/qz/test/pdf` | `qz.test.pdf` | Generate and stream a test PDF |

### Installer Downloads

| Method | URL | Name | Description |
|--------|-----|------|-------------|
| `GET` | `/qz/installer/windows` | `qz.installer` | Windows installer info |
| `GET` | `/qz/installer/linux` | `qz.installer` | Linux installer info |
| `GET` | `/qz/installer/macos` | `qz.installer` | macOS installer info |

---

## Frontend Usage — SmartPrint JS

`SmartPrint` is the JavaScript library that lives in `public/vendor/qz-tray/js/smart-print.js`. It handles the WebSocket connection, signing, queue, retry, and printer memory automatically.

---

### Method 1 — HTML Data Attributes (Zero JS)

The simplest approach. Add `data-qz-print` to any button or element. No JavaScript needed.

```html
{{-- Print a PDF --}}
<button data-qz-print="/invoices/123.pdf">
    🖨 Print Invoice
</button>

{{-- Print with specific printer --}}
<button
    data-qz-print="/invoices/123.pdf"
    data-qz-printer="HP LaserJet M404"
    data-qz-copies="2">
    🖨 Print 2 Copies
</button>

{{-- Print ZPL label --}}
<button
    data-qz-print=""
    data-qz-type="zpl"
    data-qz-data="^XA^FO50,50^ADN,36,20^FDHello World^FS^XZ"
    data-qz-printer="Zebra ZD420">
    🏷 Print Label
</button>

{{-- Print with 2 second delay --}}
<button
    data-qz-print="/receipts/99.pdf"
    data-qz-type="pdf"
    data-qz-delay="2000">
    🖨 Print (2s delay)
</button>
```

---

### Method 2 — JavaScript API

Full control via the `SmartPrint` global object.

```javascript
// Print a PDF from a URL
SmartPrint.print('/invoices/123.pdf');

// Print with options
SmartPrint.print({
    url:     '/invoices/123.pdf',
    type:    'pdf',
    printer: 'HP LaserJet M404',
    copies:  2,
    profile: 'default',   // 'default', 'small' (80mm), 'label' (100x150mm)
});

// Print ZPL
SmartPrint.printZPL('^XA^FO50,50^ADN,36,20^FDHello^FS^XZ', 'Zebra ZD420');

// Print ESC/POS
SmartPrint.printESC('\x1B\x40Hello Thermal!\n\n\n', 'Epson TM-T88');

// Print raw bytes
SmartPrint.printRaw('\x1B\x40Test\n', 'raw', 'My Printer');
```

---

### Method 3 — Global Shorthand Functions

These global helpers are available anywhere on the page:

```javascript
// Shorthand for SmartPrint.print()
smartPrint('/invoices/123.pdf');
smartPrint('/invoices/123.pdf', { printer: 'HP', copies: 3 });

// Shorthand for SmartPrint.printZPL()
smartPrintZPL('^XA^FO50,50^FDTest^FS^XZ', 'Zebra ZD420');

// Shorthand for SmartPrint.printESC()
smartPrintESC('\x1B\x40Receipt\n\n\n', 'Epson TM');
```

---

### Print Types Reference

| Type | `data-qz-type` value | When to use |
|------|----------------------|-------------|
| PDF | `pdf` | Standard documents, invoices, reports |
| HTML | `html` | Dynamic web content |
| ZPL | `zpl` | Zebra label printers |
| ESC/POS | `escpos` | Thermal receipt printers (Epson, Star) |
| Raw | `raw` | Any raw byte command |

---

### All Data Attributes Reference

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `data-qz-print` | `string` | — | URL of the file to print. **Required** for click-to-print buttons. |
| `data-qz-auto-print` | `string` | — | URL to print automatically when element loads. |
| `data-qz-type` | `string` | `pdf` | Print type: `pdf`, `html`, `zpl`, `escpos`, `raw` |
| `data-qz-printer` | `string` | saved/default | Printer name. If omitted, uses saved/default printer. |
| `data-qz-copies` | `number` | `1` | Number of copies to print. |
| `data-qz-data` | `string` | — | Raw data for ZPL/ESC/POS/raw types (instead of URL). |
| `data-qz-profile` | `string` | `default` | Paper profile: `default`, `small` (80mm), `label` (100×150mm) |
| `data-qz-delay` | `number` | `0` | Milliseconds to wait before printing. |

---

### Full SmartPrint API Reference

#### Printing

```javascript
// Print a PDF by URL
SmartPrint.print(url: string): void

// Print with full options object
SmartPrint.print(options: {
    url?:     string,    // URL of file to print
    type?:    string,    // 'pdf' | 'html' | 'zpl' | 'escpos' | 'raw'
    printer?: string,    // Printer name
    copies?:  number,    // Number of copies
    data?:    string,    // Raw data (for zpl/escpos/raw)
    profile?: string,    // 'default' | 'small' | 'label'
}): void

// Print raw/ZPL/ESC/POS data
SmartPrint.printRaw(data: string, type?: string, printer?: string): void
SmartPrint.printZPL(zpl: string, printer?: string): void
SmartPrint.printESC(escpos: string, printer?: string): void
```

#### Printer Management

```javascript
// Get all available printers (async)
const printers = await SmartPrint.getPrinters();
// Returns: string[] e.g. ['HP LaserJet', 'Zebra ZD420', 'Epson TM-T88']

// Get currently selected printer
const name = SmartPrint.getCurrentPrinter();
// Returns: string | null

// Set / remember a printer
SmartPrint.setPrinter('HP LaserJet M404');          // Remember for current URL path
SmartPrint.setPrinter('HP LaserJet M404', 'global'); // Remember globally

// Open the printer picker modal
SmartPrint.showPrinterSwitcher();

// Persistent UUID identifying THIS browser/workstation (v1.1.0+).
// Generated once via crypto.randomUUID() and kept in localStorage.
// Sent automatically as X-Device-Id on every /qz/* request; call it
// directly if you're hitting those endpoints yourself (e.g. from an
// admin dashboard) or want to display which workstation is active.
const deviceId = SmartPrint.getDeviceId();
// Returns: string (uuid)
```

#### Print Job Promises (v1.1.0+)

`print()`, `printRaw()`, `printZPL()`, and `printESC()` all return a `Promise` that resolves once the job actually reaches the printer (or the browser fallback dialog), and rejects on failure — including if the user cancels the printer-selection modal. Prior releases documented this but it silently resolved to `undefined`; it now behaves as documented.

```javascript
try {
    const { jobId, success } = await SmartPrint.print('/invoice.pdf', {
        printer: 'Office Printer',
        copies: 2,
        onComplete: (job) => console.log('Printed', job.id),
        onError:    (err, job) => console.error('Failed', job.id, err),
    });
    console.log('Job', jobId, success ? 'printed' : 'failed');
} catch (err) {
    // Rejects when: no printer available and the user cancelled the
    // picker, QZ Tray was offline, or qz.print() itself threw.
    console.error('Print failed:', err);
}
```

#### Connection

```javascript
// Connect to QZ Tray WebSocket
await SmartPrint.connect();

// Disconnect
await SmartPrint.disconnect();

// Check if connected
const ok = SmartPrint.isConnected(); // boolean
```

#### Queue Management

```javascript
// Get current queue
const queue = SmartPrint.getQueue(); // array of job objects

// Clear the queue (cancels pending jobs)
SmartPrint.clearQueue();

// Retry a specific failed job by index
SmartPrint.retryJob(0);

// Retry all offline-buffered jobs
SmartPrint.retryOffline();
```

#### Cache

```javascript
// Clear printer memory from localStorage
SmartPrint.clearCache();
```

#### Settings

```javascript
SmartPrint.getSettings();
// Returns: { defaultPrinter: 'HP LaserJet' }

SmartPrint.updateSettings({ defaultPrinter: 'Epson TM-T88' });
```

---

### Events Reference

Listen to events using `SmartPrint.on(event, callback)`:

```javascript
SmartPrint.on('connected', function(data) {
    console.log('QZ Tray connected. Printers:', data.printers);
});
```

| Event | Payload | When it fires |
|-------|---------|---------------|
| `connected` | `{ printers: string[] }` | WebSocket connection successful |
| `connection-failed` | `{ error }` | Could not connect to QZ Tray |
| `disconnected` | — | Connection closed |
| `printers-loaded` | `{ printers: string[] }` | Printer list loaded from QZ Tray |
| `printer-saved` | `{ printer, scope }` | User selected/saved a printer |
| `job-queued` | `{ job }` | Print job added to queue |
| `job-processing` | `{ job }` | Job is being sent to QZ Tray |
| `job-completed` | `{ job }` | Job sent successfully |
| `job-failed` | `{ job, error }` | Job failed (moved to offline buffer) |
| `fallback-print` | `{ job }` | Browser print dialog opened as fallback |
| `queue-cleared` | — | Queue was cleared |
| `cache-cleared` | — | Printer cache was cleared |
| `settings-updated` | `{ settings }` | Settings changed |
| `ready` | `{ printers }` | SmartPrint fully initialized |
| `init-failed` | `{ reason }` | qz-tray.min.js not loaded on page |

**Remove an event listener:**
```javascript
function onConnected(data) { /* ... */ }

SmartPrint.on('connected', onConnected);
SmartPrint.off('connected', onConnected);  // Remove
```

---

## Printing Use Cases

### Print a PDF Invoice

```blade
{{-- resources/views/invoices/show.blade.php --}}

@extends('layouts.app')

@section('content')
<div class="invoice-actions">
    {{-- Simple one-click print --}}
    <button
        data-qz-print="{{ route('invoices.pdf', $invoice) }}"
        data-qz-type="pdf"
        class="btn btn-primary">
        🖨 Print Invoice #{{ $invoice->number }}
    </button>

    {{-- Print 2 copies to a specific printer --}}
    <button
        data-qz-print="{{ route('invoices.pdf', $invoice) }}"
        data-qz-type="pdf"
        data-qz-copies="2"
        data-qz-printer="Office HP LaserJet"
        class="btn btn-secondary">
        🖨 Print 2 Copies
    </button>
</div>
@endsection
```

Your Laravel route just returns a PDF response as normal:

```php
// routes/web.php
Route::get('/invoices/{invoice}/pdf', function (Invoice $invoice) {
    $pdf = PDF::loadView('invoices.pdf', compact('invoice'));
    return $pdf->stream('invoice-'.$invoice->number.'.pdf');
})->name('invoices.pdf');
```

---

### Print a ZPL Label (Zebra)

```blade
{{-- Using data attributes --}}
<button
    data-qz-print=""
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

```javascript
// Using JavaScript (dynamic ZPL from server)
fetch('/api/labels/' + productId + '/zpl')
    .then(r => r.text())
    .then(zpl => {
        SmartPrint.printZPL(zpl, 'Zebra ZD420');
    });
```

**Laravel route returning ZPL:**

```php
Route::get('/api/labels/{product}/zpl', function (Product $product) {
    $zpl = "^XA\n"
         . "^FO50,50^ADN,36,20^FD{$product->name}^FS\n"
         . "^FO50,100^BCN,80,Y,N,N^FD{$product->barcode}^FS\n"
         . "^XZ";

    return response($zpl, 200, ['Content-Type' => 'text/plain']);
});
```

---

### Print an ESC/POS Receipt (Thermal)

```javascript
// Build ESC/POS receipt in JavaScript
const ESC = '\x1B';
const GS  = '\x1D';

const receipt = [
    ESC + '\x40',              // Initialize
    ESC + '\x61\x01',         // Center align
    ESC + '\x21\x30',         // Double height+width
    'MY STORE\n',
    ESC + '\x21\x00',         // Normal text
    ESC + '\x61\x00',         // Left align
    '--------------------------------\n',
    'Item 1               $10.00\n',
    'Item 2                $5.50\n',
    '--------------------------------\n',
    ESC + '\x45\x01',         // Bold on
    'TOTAL               $15.50\n',
    ESC + '\x45\x00',         // Bold off
    '\n\n\n',
    GS + '\x56\x41',          // Full cut
].join('');

SmartPrint.printESC(receipt, 'Epson TM-T88VI');
```

**Or generate the receipt server-side and fetch it:**

```php
// Laravel controller
public function printReceipt(Order $order)
{
    $lines = [];
    $lines[] = "\x1B\x40";        // Init
    $lines[] = "\x1B\x61\x01";    // Center
    $lines[] = $order->store_name . "\n";
    $lines[] = "\x1B\x61\x00";    // Left

    foreach ($order->items as $item) {
        $lines[] = str_pad($item->name, 24) . str_pad('$'.$item->price, 8, ' ', STR_PAD_LEFT) . "\n";
    }

    $lines[] = "\n\n\n";
    $lines[] = "\x1D\x56\x41";    // Cut

    return response(implode('', $lines), 200, [
        'Content-Type' => 'application/octet-stream',
    ]);
}
```

```javascript
fetch('/orders/{{ $order->id }}/receipt')
    .then(r => r.text())
    .then(data => SmartPrint.printESC(data, 'Epson TM-T88'));
```

---

### Auto-Print on Page Load

Print automatically when the page loads, with no button click needed:

```html
{{-- Print as soon as this element is in the DOM --}}
<div
    data-qz-auto-print="{{ route('invoices.pdf', $invoice) }}"
    data-qz-type="pdf"
    data-qz-printer="Receipt Printer">
</div>
```

With a delay (useful for "print after save" flows):

```html
{{-- Print 2 seconds after page load --}}
<div
    data-qz-auto-print="{{ route('invoices.pdf', $invoice) }}"
    data-qz-delay="2000">
</div>
```

Using JavaScript:

```javascript
// Print after 1 second
setTimeout(() => {
    SmartPrint.print('/orders/{{ $order->id }}/pdf');
}, 1000);

// Print when a condition is met
SmartPrint.on('connected', () => {
    SmartPrint.print('/invoices/{{ $invoice->id }}/pdf');
});
```

---

### Print with Copies and Delay

```html
<button
    data-qz-print="/labels/{{ $batch->id }}.pdf"
    data-qz-copies="10"
    data-qz-delay="500">
    🖨 Print 10 Labels
</button>
```

```javascript
SmartPrint.print({
    url:     '/shipping/{{ $shipment->id }}.pdf',
    type:    'pdf',
    copies:  3,
    printer: 'Shipping Label Printer',
});
```

---

### Printer Switcher Modal

Show a modal for the user to pick a printer:

```html
{{-- Button to open the switcher --}}
<button onclick="SmartPrint.showPrinterSwitcher()">
    🖨 Change Printer
</button>
```

**Or use the keyboard shortcut:** Press `Ctrl + Shift + P` anywhere on the page.

The modal shows all available printers. The user clicks one and it is saved (per-page by default) in `localStorage`.

**To set a printer programmatically:**

```javascript
// Set for current URL path only
SmartPrint.setPrinter('HP LaserJet M404');

// Set globally (remembered across all pages)
SmartPrint.setPrinter('HP LaserJet M404', 'global');
```

---

### Print Queue with Retry

SmartPrint automatically queues jobs and processes them one by one. Jobs that fail are saved to a retry buffer.

```javascript
// Queue multiple jobs — they print in order
SmartPrint.print('/invoices/1.pdf');
SmartPrint.print('/invoices/2.pdf');
SmartPrint.print('/invoices/3.pdf');

// Show what is in the queue
console.log(SmartPrint.getQueue());

// Clear the queue (cancels all pending)
SmartPrint.clearQueue();

// Retry a failed job by index
SmartPrint.retryJob(0);

// Show a queue status indicator in your UI
SmartPrint.on('job-queued',     d => updateQueueCount(SmartPrint.getQueue().length));
SmartPrint.on('job-completed',  d => updateQueueCount(SmartPrint.getQueue().length));
SmartPrint.on('queue-cleared',  ()  => updateQueueCount(0));
```

Add the built-in queue UI to your page:

```html
{{-- This element is updated automatically by SmartPrint --}}
<ul id="sp-queue-list"></ul>
```

---

### Offline Queue (Print When Reconnected)

If QZ Tray is offline when a job is submitted, SmartPrint saves it to `localStorage`. When QZ Tray reconnects, the jobs print automatically.

```javascript
// Manually trigger retry of offline jobs
SmartPrint.retryOffline();

// Listen for when a job is buffered offline
SmartPrint.on('job-failed', function(data) {
    showNotification('QZ Tray offline — job saved. Will print when reconnected.');
});
```

---

### Listen to Print Events

Use events to update your UI, track completions, or log to your backend:

```javascript
SmartPrint.on('connected', function(data) {
    document.getElementById('printer-status').textContent = '🟢 QZ Tray Connected';
    document.getElementById('printer-name').textContent = SmartPrint.getCurrentPrinter() || 'None selected';
});

SmartPrint.on('connection-failed', function() {
    document.getElementById('printer-status').textContent = '🔴 QZ Tray Offline';
    document.getElementById('install-link').style.display = 'block';
});

SmartPrint.on('job-completed', function(data) {
    // Log to your backend
    fetch('/print-log', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
            url:       data.job.url,
            printer:   data.job.printer,
            completed: new Date().toISOString(),
        }),
    });
});

SmartPrint.on('fallback-print', function() {
    alert('QZ Tray is not running. Opening browser print dialog instead.');
});
```

---

### Using the ZPL Adapter

The package includes a ZPL helper (`public/vendor/qz-tray/js/adapters/zpl.js`). Include it alongside `smart-print.js`:

```html
<script src="{{ asset('vendor/qz-tray/js/adapters/zpl.js') }}"></script>
```

```javascript
// Build a ZPL label programmatically
const label = ZPL.label()
    .text('Product Name', 50, 50, { size: 'large' })
    .text('SKU-12345', 50, 100, { size: 'medium' })
    .barcode('1234567890', 50, 150, { height: 80 })
    .build();

SmartPrint.printZPL(label, 'Zebra ZD420');
```

---

### Using the ESC/POS Adapter

Include `adapters/escpos.js`:

```html
<script src="{{ asset('vendor/qz-tray/js/adapters/escpos.js') }}"></script>
```

```javascript
// Build an ESC/POS receipt
const receipt = ESCPOS.receipt()
    .initialize()
    .align('center')
    .bold(true).text('MY STORE').bold(false)
    .align('left')
    .divider()
    .line('Item 1', '$10.00')
    .line('Item 2', '$5.50')
    .divider()
    .bold(true).line('TOTAL', '$15.50').bold(false)
    .feed(3)
    .cut()
    .build();

SmartPrint.printESC(receipt, 'Epson TM-T88VI');
```

---

## Laravel Backend Printing (Server-Side)

You can trigger a print from a Laravel controller — for example, after saving an order:

```php
// app/Http/Controllers/OrderController.php

public function store(Request $request)
{
    $order = Order::create($request->validated());

    // Return page with auto-print directive
    return view('orders.created', [
        'order'    => $order,
        'autoPrint' => true,
        'printUrl'  => route('orders.pdf', $order),
    ]);
}
```

```blade
{{-- resources/views/orders/created.blade.php --}}

@if($autoPrint)
    <div
        data-qz-auto-print="{{ $printUrl }}"
        data-qz-type="pdf"
        data-qz-delay="500">
    </div>
@endif
```

**Or redirect with a flash variable:**

```php
return redirect()->route('orders.show', $order)
    ->with('auto_print', route('orders.pdf', $order));
```

```blade
{{-- In your layout or show view --}}
@if(session('auto_print'))
    <div data-qz-auto-print="{{ session('auto_print') }}" data-qz-type="pdf"></div>
@endif
```

---

## Database — Print Job Logging

Two tables ship with this package. Both are optional — the package works with neither migrated (job logging and server-synced printer memory just silently no-op), but you'll want both for anything beyond a single-page demo.

### `qz_print_jobs`

Every `POST /qz/print` request writes a row here (that's what `smart-print.js` calls internally after each successful `qz.print()` — see [Full SmartPrint API Reference](#full-smartprint-api-reference)). You don't need to write to it manually.

| Column | Type | Description |
|--------|------|-------------|
| `id` | `uuid` or `bigint` | Primary key — see [`id_type`](#configuration-reference) config. `uuid` (default) means the id is never a guessable sequential integer and is safe to return straight to the client; also the value `GET /qz/jobs`/`DELETE /qz/jobs/{id}` operate on. |
| `tenant_id` | string, nullable | Bigint or UUID, stored as a string so it works with either — see [Multi-Tenant Support](#multi-tenant-support) below. |
| `user_id` / `user_type` | nullable | Polymorphic (`nullableMorphs`) so it works with integer- or UUID-keyed user models. |
| `device_id` | uuid, nullable | The workstation/browser that submitted the job (`X-Device-Id` header — see `SmartPrint.getDeviceId()`). |
| `printer_name` | string | Name of the printer used |
| `document_url` | string, nullable | URL of the printed document |
| `document_type` | string | `pdf`, `zpl`, `escpos`, `raw`, `html` |
| `copies` | int | Number of copies |
| `status` | string | `pending`, `processing`, `completed`, `failed`, `cancelled` |
| `error_message` | text, nullable | Populated when a job fails |
| `metadata` | JSON, nullable | Any extra data |
| `processed_at` | timestamp, nullable | When the job was completed/cancelled |
| `created_at` / `updated_at` | timestamp | Standard Eloquent timestamps |

**Querying your own job history:**

```php
use Illuminate\Support\Facades\DB;

$jobs = DB::table('qz_print_jobs')
    ->where('user_id', auth()->id())
    ->where('status', 'completed')
    ->orderByDesc('created_at')
    ->paginate(20);
```

**Enable request-level logging** (separate from the DB rows above — this writes to your Laravel log file, not the database):

```php
// config/qz-tray.php
'logging' => [
    'enabled' => true,
    'channel' => 'daily',
    'level'   => 'info',
],
```

### `qz_printer_preferences`

Server-side backup for printer memory (the client-side source of truth is `localStorage`; this table exists so a cleared browser profile or a fresh browser on the same physical workstation can pick its printer back up — see [Printer Switcher Modal](#printer-switcher-modal)). One row per `(tenant_id, identity_type, identity_value, path)` combination.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Auto-increment primary key |
| `tenant_id` | string | `''` when not applicable — deliberately not `null`; MySQL's unique index treats `NULL` as distinct-from-itself, which would silently stop enforcing uniqueness for the common single-tenant case if this were nullable |
| `identity_type` | string | `device`, `user`, or `session` — see [`identity_priority`](#configuration-reference) |
| `identity_value` | string | The device UUID, user id, or session id |
| `path` | string | The URL path the printer was remembered for |
| `printer_name` | string | The remembered printer |
| `created_at` / `updated_at` | timestamp | Standard Eloquent timestamps |

Rows aren't pruned automatically — see [`qz:prune-preferences`](#artisan-commands) in Artisan Commands.

---

## Multi-Tenant Support

Every table above is tenant-aware, and — critically — **doesn't assume your tenant/project model's primary key type**. Some apps key tenants by `bigint`, others by `uuid`; both work unmodified because `tenant_id` is stored as a string, validated as either shape:

```php
// POST /qz/print, POST /qz/printer — either param name works, same column
[
    'tenant_id' => '482',                                     // bigint-keyed tenant, OR
    'project_id' => 'b2b1f6c0-3b3d-4c9a-9e2e-1a2b3c4d5e6f',    // uuid-keyed tenant
]
```

**Auto-tagging every request without passing it at every call site** — set a resolver once in config, used only when the request doesn't supply `tenant_id`/`project_id` explicitly:

```php
// config/qz-tray.php
'tenant_id_resolver' => fn ($request) => auth()->user()?->tenant_id,

// or for a package like stancl/tenancy:
'tenant_id_resolver' => fn ($request) => tenant('id'),
```

**Filtering print jobs by tenant:**

```php
$jobs = DB::table('qz_print_jobs')
    ->where('tenant_id', (string) auth()->user()->tenant_id)
    ->where('status', 'completed')
    ->orderByDesc('created_at')
    ->paginate(20);
```

**Scoping printer memory to a tenant too** (not just job history) — pass the same `tenant_id`/`project_id` on `POST /qz/printer`, or set it once page-wide from your Blade layout so `smart-print.js` sends it automatically on every printer-memory and job-logging call:

```html
<script>
    window.QZ_CONFIG = {
        tenantId: '{{ auth()->user()?->tenant_id }}',
        // or: projectId: '{{ $project->id }}'
    };
</script>
```

The `GET /qz/jobs` queue listing also narrows by tenant when one is present, on top of its existing per-device/per-user scoping — see [All Available Routes / API Endpoints](#all-available-routes--api-endpoints).

---

## Security

### Route Protection

By default routes have only `web` middleware. **Add `auth` in production:**

```php
// config/qz-tray.php
'routes' => [
    'prefix'     => 'qz',
    'middleware' => ['web', 'auth'],  // Require authenticated user
],
```

Or apply additional middleware:

```php
'middleware' => ['web', 'auth', 'verified', 'role:printer'],
```

### CSRF Protection

All POST requests from SmartPrint.js automatically include the CSRF token from the `<meta name="csrf-token">` tag. No extra setup needed.

### Certificate Security

- The **private key** (`storage/qz/private-key.pem`) is only used server-side to sign requests. It is never sent to the browser.
- The **public certificate** (`storage/qz/digital-certificate.txt`) is sent to QZ Tray so it knows to trust your domain.
- QZ Tray uses this certificate to verify that print requests genuinely came from your application.
- Never enable `allow_public_cert_generate` in production.

### Rate Limiting

The signing endpoint is the most sensitive — consider adding rate limiting:

```php
// config/qz-tray.php
'routes' => [
    'throttle' => '60,1',  // 60 requests per minute per IP
],
```

---

## Environment Variables Reference

**None of these are required** — every one has a working default and the package runs fully without a `.env` entry for any of them. Add only the ones you want to override.

| Variable | Package default | What it controls |
|---|---|---|
| `QZ_DEFAULT_PRINTER` | *(none — user must pick)* | Pre-select a printer instead of prompting on first print |
| `QZ_WEBSOCKET_HOST` | `localhost` | Host QZ Tray's WebSocket listens on |
| `QZ_WEBSOCKET_PORT` | `8181` | Port QZ Tray's WebSocket listens on |
| `QZ_AUTO_GENERATE_CERT` | `false` | Auto-generate a self-signed cert on boot — dev/local only |
| `QZ_ALLOW_PUBLIC_CERT_GENERATE` | `false` | Allow cert (re)generation via HTTP — leave `false` in production |
| `QZ_LOGGING_ENABLED` | `false` | Write every `POST /qz/print` request to your Laravel log |
| `QZ_LOGGING_CHANNEL` | `stack` | Which log channel to use, if enabled |
| `QZ_LOGGING_LEVEL` | `info` | Log level, if enabled |
| `QZ_JOB_ID_TYPE` | `uuid` | `qz_print_jobs` primary key type — `uuid` or `bigint`. Read at migration time; set **before** the first `php artisan migrate` |
| `QZ_UUID_VERSION` | `v7` | `v7` (time-ordered, better DB index locality) or `v4` (fully random), when `QZ_JOB_ID_TYPE=uuid` |
| `QZ_API_ENABLED` | `false` | Expose the stateless Sanctum-protected API routes (`routes/api.php`) alongside the standard session-based ones |

**Copy-paste block — every variable at its package default** (harmless to add as-is; edit only what you're actually changing):

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

**A realistic production override** — name your printer, generate the cert once via `php artisan qz:generate-certificate` instead of auto-generating it, and turn on job logging:

```env
QZ_DEFAULT_PRINTER="HP LaserJet M404"
QZ_AUTO_GENERATE_CERT=false
QZ_LOGGING_ENABLED=true
QZ_LOGGING_CHANNEL=daily
```

---

## Troubleshooting

### "Certificate not found"

```bash
php artisan qz:generate-certificate
```

Check that `storage/qz/` is writable:

```bash
chmod -R 775 storage/qz
```

### "Could not connect — is QZ Tray running?"

1. Install QZ Tray from [qz.io/download](https://qz.io/download)
2. Start QZ Tray — look for the icon in the system tray
3. Check it's running on port 8181 (default)
4. Make sure the browser page is served over `http://` or `https://` (not `file://`)

### Connection works but prints fail

- Open `/qz/status` — check `certificate` and `private_key` are both `"present"`
- Open `/qz/test` — test the connection interactively
- Make sure `<meta name="csrf-token" content="{{ csrf_token() }}">` is in your `<head>`
- Check browser console for errors

### "CSRF token mismatch" on `/qz/sign`

Ensure the CSRF meta tag is present:

```html
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
```

And that `/qz/sign` is not excluded in `VerifyCsrfToken`:

```php
// app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    // Do NOT add 'qz/sign' here
];
```

### Printer list is empty

The printer list comes from QZ Tray via WebSocket — not from the server. If empty:

1. Confirm QZ Tray is running (system tray icon visible)
2. Confirm the browser connected (check `/qz/smart` for status indicator)
3. Try clicking "Refresh Printers" on `/qz/smart`

### Printing works in development but not production (HTTPS)

QZ Tray 2.x supports HTTPS. Ensure:
- Your site is on `https://`
- The certificate at `/qz/certificate` loads without errors
- QZ Tray 2.x is installed (not 1.x)

### "openssl_sign failed"

The private key may be corrupted. Regenerate:

```bash
php artisan qz:generate-certificate --force
```

### Clear all caches

```bash
php artisan qz:clear-cache --all
php artisan qz:prune-preferences --older-than=0   # delete every stored printer preference, not just this identity's
php artisan cache:clear
php artisan config:clear
```

---

## File Structure

After running `php artisan qz:install`, the following files are published:

```
your-laravel-app/
├── config/
│   └── qz-tray.php                     ← Main configuration
│
├── storage/
│   └── qz/
│       ├── digital-certificate.txt     ← Public certificate (auto-generated)
│       └── private-key.pem             ← Private key — never expose this
│
├── database/
│   └── migrations/
│       ├── ..._create_qz_print_jobs_table.php
│       └── ..._create_qz_printer_preferences_table.php
│
├── resources/
│   └── views/
│       └── vendor/
│           └── qz-tray/
│               ├── default.blade.php   ← Full QZ demo page (/qz/test)
│               ├── smart.blade.php     ← SmartPrint demo page (/qz/smart)
│               └── example.blade.php  ← Usage examples
│
└── public/
    └── vendor/
        └── qz-tray/
            ├── js/
            │   ├── qz-tray.js          ← QZ Tray WebSocket library
            │   ├── smart-print.js      ← SmartPrint library ⭐
            │   ├── printer-status.js   ← Printer status widget
            │   ├── printer-switcher.js ← Printer switcher widget
            │   └── adapters/
            │       ├── zpl.js          ← ZPL label helper
            │       ├── escpos.js       ← ESC/POS receipt helper
            │       └── raw-print.js    ← Raw print helper
            ├── css/
            │   ├── bootstrap.min.css
            │   ├── font-awesome.min.css
            │   └── style.css
            └── fonts/
                └── (Font Awesome fonts)
```

**Package source (inside `vendor/bitdreamit/laravel-qz-tray/`):**

```
src/
├── QzTrayServiceProvider.php           ← Registers routes, commands, views
├── Http/
│   └── Controllers/
│       └── QzSecurityController.php    ← All route handlers (security, printers, jobs, cache)
└── Console/
    └── Commands/
        ├── InstallQzTray.php           ← php artisan qz:install
        ├── GenerateCertificate.php     ← php artisan qz:generate-certificate
        ├── ClearQzCache.php            ← php artisan qz:clear-cache
        └── PrunePreferences.php        ← php artisan qz:prune-preferences
```

---

## Upgrade Guide

### From v0.x to v1.0

1. Update composer:
   ```bash
   composer update bitdreamit/laravel-qz-tray
   ```

2. Re-publish assets (use `--force` to overwrite):
   ```bash
   php artisan vendor:publish --tag=qz-assets --force
   php artisan vendor:publish --tag=qz-config --force
   ```

3. Regenerate certificate:
   ```bash
   php artisan qz:generate-certificate --force
   ```

4. Run migrations:
   ```bash
   php artisan migrate
   ```

5. Update HTML — replace old `data-smart-print` attributes with `data-qz-print`:
   ```html
   {{-- Old --}}
   <button data-smart-print="/invoice.pdf">Print</button>

   {{-- New (both work, but qz-print is preferred) --}}
   <button data-qz-print="/invoice.pdf">Print</button>
   ```

---

## FAQ

**Q: Does this package require a license from QZ Tray?**
A: QZ Tray Community Edition is free for internal/self-hosted use. A commercial license is required for redistribution. See [qz.io/pricing](https://qz.io/pricing).

**Q: Does this work on mobile devices?**
A: No. QZ Tray is a desktop application. Mobile browsers cannot connect to a local WebSocket server. Mobile devices need to use the standard browser print dialog.

**Q: Can multiple users print to different printers at the same time?**
A: Yes. Each browser tab has its own WebSocket connection to QZ Tray on that machine. Users on different machines print to their own locally-connected printers independently.

**Q: What happens if QZ Tray is not installed on a client machine?**
A: SmartPrint falls back to opening the browser's print dialog (if `fallback.enabled = true` in config). You can also listen to the `connection-failed` event and show a download link.

**Q: Can I use this with React, Vue, or Livewire?**
A: Yes. `SmartPrint` is a plain JavaScript object on `window`. Call it from any framework:
```javascript
// React
window.SmartPrint.print('/invoices/' + id + '.pdf');

// Vue
SmartPrint.print({ url: '/invoices/' + this.invoice.id + '.pdf', copies: 2 });

// Livewire (in @script)
SmartPrint.print('/invoices/' + $wire.invoiceId + '.pdf');
```

**Q: Can I print to a network printer (not USB)?**
A: Yes. QZ Tray supports network printers. Set the printer name to the network printer's name as it appears in Windows/macOS printer settings.

**Q: Is the private key secure?**
A: Yes — the private key lives in `storage/qz/` which is not web-accessible. It is used only by PHP to sign requests, and is never sent to the client.

**Q: The certificate expires after 20 years — do I need to renew it?**
A: Yes, but after 20 years. You can regenerate at any time with `php artisan qz:generate-certificate --force` — just ensure the new certificate is pushed to production.

---

## License

MIT License — see [LICENSE](LICENSE) for details.

---

## Support

- **GitHub Issues:** [github.com/bitdreamit/laravel-qz-tray/issues](https://github.com/bitdreamit/laravel-qz-tray/issues)
- **Email:** info@bitdreamit.com
- **QZ Tray Documentation:** [qz.io/api](https://qz.io/api)

---

<p align="center">Made with ❤️ by <a href="https://bitdreamit.com">Bit Dream IT</a></p>
