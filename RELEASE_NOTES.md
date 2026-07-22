# Laravel QZ Tray — Releases & Changelog

All notable changes to **bitdreamit/laravel-qz-tray** are documented here.  
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [v1.1.4] — 2026-07-23

> Laravel 13 support, and the vendored `qz-tray.js` now genuinely runs 2.2.6 (not just a relabeled 2.2.5).

### ✨ New Support

- **Laravel 13** (released March 17, 2026, PHP 8.3+) — `illuminate/support`, `illuminate/database`, `illuminate/routing` constraints widened to `^10.0|^11.0|^12.0|^13.0`. No code changes were needed: Laravel 13's own release notes describe it as zero-breaking-change from 12, and this package doesn't touch any of the few areas that did change (MySQL `DELETE ... JOIN/ORDER BY/LIMIT` compilation, polymorphic pivot table naming, CSRF origin config). The package's own `php: ^8.1` constraint didn't need bumping either — it's a floor, and PHP 8.3+ (required by Laravel 13) already satisfies it.
- Also fixed `require-dev` `orchestra/testbench`, which had never been updated for Laravel 12 support in the first place (still `^8.0|^9.0` covering only Laravel 10/11) — now `^8.0|^9.0|^10.0|^11.0`, plus `phpunit/phpunit` `^12.0` for the Laravel 13/PHP 8.3 combination.

### 🐛 Bug Fix

- **BUG-26** — `resources/js/qz-tray.js` (the vendored, self-hostable copy of the actual QZ Tray library — separate from the CDN reference bumped in BUG-23/v1.1.0) still both labeled *and ran* genuine 2.2.5 code. Verified the vendored copy was byte-identical to real upstream 2.2.5 (no local customizations to lose), then replaced it with genuine upstream 2.2.6 from the npm registry — not just an edited version comment. Used by `default.blade.php`/`example.blade.php` via `public/vendor/qz-tray/js/qz-tray.js`.

---

## [v1.1.3] — 2026-07-22

> Job ids are now UUIDv7 (time-ordered, RFC 9562) by default instead of v4, with automatic per-request fallback to v4.

### ✨ Improvement

- **UUIDv7 job ids** — when `qz-tray.id_type` is `'uuid'` (default), ids are now generated as v7 instead of v4 by default, controlled by new `qz-tray.uuid_version` config (`'v7'` default | `'v4'`). v7 embeds a millisecond timestamp in the first 48 bits, so ids sort roughly by creation time — far better B-tree index locality for a write-heavy table like `qz_print_jobs` than v4's fully-random layout, which scatters every insert to a random leaf page. Both versions are valid values for the same `uuid` column, so this isn't a schema change.
- **Server-side**: new `generateUuid()` helper tries `Str::uuid7()` (native since Laravel 11) and transparently falls back to `Str::uuid()` (v4) when the method doesn't exist — this package supports Laravel 10.x, which has no native v7 support — or if generation throws for any other reason (e.g. an incompatible pinned `ramsey/uuid`).
- **Client-side**: `smart-print.js` gained a manual RFC 9562 v7 generator (no browser exposes a native v7 API yet — `crypto.randomUUID()` is v4-only), used for every job id via a new `generateJobId()` entry point, falling back to the existing v4 generator (`uuid4()`) if v7 generation throws or `window.QZ_CONFIG.uuidVersion === 'v4'` is set.
- Device ids (`X-Device-Id`, from `getDeviceId()`) are unaffected and remain v4 — they're generated once and never queried by time range, so there's no index-locality benefit to gain there.

### ⬆️ Upgrade Notes

No schema or breaking change. Purely a generation-algorithm swap for `id_type = 'uuid'` installs; existing v4 rows and new v7 rows coexist fine in the same column. Set `QZ_UUID_VERSION=v4` in `.env` to keep the old behavior.

---

## [v1.1.2] — 2026-07-22

> Implements all four v1.1.0/v1.1.1 "Recommendations (not fixed)" items.

### ✨ New Features / Fixes

1. **Device id visibility** — `printer-status.js` gained an opt-in `showDeviceId` option (default `false`). When enabled, the status widget shows a short device UUID fragment with the full id on hover — for confirming which workstation identity a shared/kiosk PC is using without opening devtools.
2. **`identity_priority` default left as-is** — this was already a one-line config change (`['user', 'device', 'session']` vs. the default `['device', 'user', 'session']`), not a bug; no code change needed, just a reminder it's there.
3. **`qz:prune-preferences` command** — new artisan command (`--older-than=90`, `--type=`, `--dry-run`) to delete stale `qz_printer_preferences` rows, since the DB-backed printer memory introduced in v1.1.0 doesn't expire on its own the way the old Cache TTL did. Not scheduled automatically — wire it into your own scheduler if row growth matters for your install. Also fixed `qz:clear-cache`, which had not been updated for the v1.1.0 storage change and was still only clearing the no-longer-written-to Cache keys.
4. **`qz_printer_preferences` is now tenant-scoped** — new `tenant_id` column (same bigint-or-uuid string convention as `qz_print_jobs.tenant_id`, defaulting to `''` rather than `null` so the composite unique index keeps enforcing correctly for single-tenant installs — MySQL treats `NULL` as distinct-from-itself in unique indexes). Folded into `setPrinter`/`getPrinter`/`clearCache` via a new shared `resolveTenantId()` helper (also de-duplicates what `print()`/`jobs()` had inlined separately). `smart-print.js` sends `tenant_id` on printer-memory calls too now, not just job logging.

### ⬆️ Upgrade Notes

Schema change to `qz_printer_preferences` (new `tenant_id` column + updated unique index). If you already ran the v1.1.0/v1.1.1 migration:
```bash
php artisan migrate:rollback --step=1   # drops qz_printer_preferences
php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider" --tag=qz-migrations --force
php artisan migrate
```

---

## [v1.1.1] — 2026-07-22

> Consolidates `qz_print_jobs`' two id columns (`id` bigint + `uuid` string) into one, type controlled by config.

### 💥 Schema Change

- **`qz_print_jobs.id` is now config-driven** via the new `qz-tray.id_type` setting (`'uuid'` default, or `'bigint'`). The separate `uuid` column from v1.1.0 has been removed — there is exactly one id column again, but its *type* is now a config choice instead of hardcoded to `unsignedBigInteger`:
  - `id_type = 'uuid'` — `id` is a `uuid` primary key. The client-generated job id (`smart-print.js`, `crypto.randomUUID()`) is written straight to `id`, so the id returned to the browser always matches the row, with no separate lookup column.
  - `id_type = 'bigint'` — `id` is a normal auto-increment integer, same as pre-1.1. `job_id` sent by the client is accepted but not used as the PK; the response's `job_id` becomes the real auto-increment value once the insert completes.
- Read at migration time (`config('qz-tray.id_type')` inside the migration's `up()`), so set it in `.env`/config **before** running `php artisan migrate` for the first time. Changing it afterward has no retroactive effect — write a follow-up migration if you need to convert an existing install.
- `GET /qz/jobs` and `DELETE /qz/jobs/{id}` now read/query the `id` column in both modes (previously `uuid`-only).

### ⬆️ Upgrade Notes

If you already ran the v1.1.0 migration (which had both `id` and `uuid` columns), this is a breaking schema change — either:
```bash
php artisan migrate:rollback --step=1   # drops qz_print_jobs (v1.1.0's migration)
php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider" --tag=qz-migrations --force
php artisan migrate
```
or write your own follow-up migration to drop the `uuid` column and convert `id`'s type in place if you have existing job history to preserve.

---

## [v1.1.0] — 2026-07-22

> UUID device identity, multi-workstation printer-memory correctness, and queue management wired end-to-end. See `BUG_REPORT.md` addendum for full detail (BUG-19 to BUG-23).

### 🐛 Bug Fixes

- **CRITICAL** — Fixed server-side printer memory (`/qz/printer`) leaking across users/workstations. It previously fell back to a single identity-less `Cache` key per URL path, so one workstation's printer choice could silently become another's default. Replaced with a `qz_printer_preferences` table scoped to `(identity_type, identity_value, path)`.
- **HIGH** — Fixed `SmartPrint.print()` (and `printRaw`/`printZPL`/`printESC`) never returning the underlying job promise — `await SmartPrint.print(...)`, documented in the README since v1.0.0, always resolved to `undefined`.
- **HIGH** — Fixed `job.onComplete`/`job.onError` callbacks (documented in the "Options Object" section) never being invoked anywhere.
- **HIGH** — Fixed `GET /qz/jobs` and `DELETE /qz/jobs/{id}` being hardcoded stubs that never queried the database, making the print-queue management endpoints unreachable in practice.
- **HIGH** — Fixed the printer-selection modal orphaning a job's promise by re-enqueueing a cloned object instead of the original — a caller awaiting `SmartPrint.print()` before any printer was chosen would hang forever.
- **LOW** — Bumped pinned QZ Tray client library from `2.2.5` to `2.2.6` (upstream fixed a websocket race condition and improved hardware I/O locking/concurrency).
- **MEDIUM** — `qz_print_jobs.tenant_id` was `unsignedBigInteger`-only and hardcoded to `null` — same bigint-vs-uuid problem `user_id` had pre-1.1, and never actually populated by any code path. Now a nullable string column accepting either a bigint id or a UUID, actually settable via `tenant_id`/`project_id` on `POST /qz/print`.

### ✨ New Features

- **Device UUID identity** — `smart-print.js` generates and persists a UUID per browser/workstation (`localStorage`, `crypto.randomUUID()`), sent as `X-Device-Id` on every request. Exposed via `SmartPrint.getDeviceId()`.
- **`qz-tray.identity_priority` config** — controls whether printer memory resolves by `device`, `user`, or `session` first when more than one applies to a request. Defaults to `device` first (correct for shared lab/kiosk workstations where the physical machine, not the logged-in user, determines the printer).
- **Server-synced printer memory** — `smart-print.js` now optionally backs up/restores printer selection via the server (opt-out with `window.QZ_CONFIG.serverSync = false`), in addition to `localStorage`.
- **Real, correlated job IDs** — print jobs use client-generated UUIDs that match the `uuid` column on `qz_print_jobs`, so `jobs()` and `cancelJob()` now operate on real, identity-scoped data instead of stubs.
- **New migration** `2026_07_22_000000_create_qz_printer_preferences_table.php` — durable, identity-scoped printer memory storage.
- **`tenant_id`/`project_id` dual bigint/UUID support** — `POST /qz/print` accepts either name for the same column; validated as either an integer id or a UUID, so this package works unmodified whether the host project's tenant/project table is bigint- or UUID-keyed. Optional `qz-tray.tenant_id_resolver` config auto-tags jobs for multi-tenant apps that don't want to pass it at every call site.

### 📦 Compatibility

| | Version |
|---|---|
| PHP | 8.1, 8.2, 8.3 |
| Laravel | 10.x, 11.x, 12.x |
| QZ Tray | 2.2.6 |
| ext-openssl | Required |

### ⬆️ Upgrade Notes

```bash
php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider" --tag=qz-migrations --force
php artisan migrate
```

No breaking changes to public JS/PHP API surfaces — all new behavior is additive or corrects a previously-broken documented contract.

---

## [v1.0.0] — 2026-05-19 🎉 Current Stable Release

> **Full rewrite and stabilisation pass.** Every known bug from v0.x has been fixed, the JavaScript library has been completely cleaned up, and full documentation is published.

### 🐛 Bug Fixes

- **CRITICAL** — Removed fatal `use Mpdf\Mpdf` import in `QzSecurityController`. `mpdf/mpdf` is not in `composer.json`, causing a class-not-found fatal error on every controller request.
- **CRITICAL** — Fixed `smart-print.js` entire function body duplicated inside the IIFE. All functions (`setupSecurity`, `connectQZ`, `processQueue`, `bind`, etc.) were defined twice — the second copy silently shadowed the first.
- **CRITICAL** — Added missing `generateCertificatePublic()` controller method. The route `POST /qz/generate` was registered but the method did not exist, throwing `BadMethodCallException` on every request.
- **CRITICAL** — Added missing `testSign()` controller method. The route `POST /qz/test-sign` was registered but the method did not exist.
- **CRITICAL** — Added missing `smart.blade.php` view. `QzSecurityController::smart()` called `view('qz-tray::smart')` but the file was never created, throwing `ViewNotFoundException` on every visit to `/qz/smart`.
- **HIGH** — Fixed `testPdf()` had no `return` statement. Method created a response but never returned it; Laravel threw "response must be a string or Responsable" error.
- **HIGH** — Removed non-existent Facade alias from `composer.json`. `extra.laravel.aliases` pointed to `Bitdreamit\QzTray\Facades\QzTray` which does not exist, causing a fatal class-not-found during package auto-discovery.
- **HIGH** — Fixed `smart-print.js` `bind()` only listened for `data-smart-print` but README documents `data-qz-print`. Clicking any documented button did nothing.
- **MEDIUM** — Fixed `processQueue()` race condition. Multiple rapid clicks triggered concurrent queue processing. Added `processingQueue` boolean lock and converted to `while` loop drain.
- **MEDIUM** — Fixed `openPrinterModal({})` crash when called from the `Ctrl+Shift+P` hotkey. Empty job object caused an error when the modal's printer button tried to enqueue it. Added guard: `if (jobToQueue && (jobToQueue.url || jobToQueue.data))`.
- **MEDIUM** — Fixed `BroadcastChannel` not guarded for unsupported browsers. `new BroadcastChannel(...)` throws in older Safari and some mobile browsers. Added `typeof BroadcastChannel !== 'undefined'` check.
- **LOW** — Fixed `openssl_free_key()` and `openssl_x509_free()` deprecated in PHP 8.0+. Both functions are no-ops in PHP 8 and emit deprecation notices. Guarded with `PHP_VERSION_ID < 80000` in `QzTrayServiceProvider`, `QzSecurityController`, and `GenerateCertificate` command.
- **LOW** — Fixed default WebSocket port `8182` → `8181`. QZ Tray's actual default port is 8181. The wrong default meant zero connections out of the box unless manually configured.
- **LOW** — Fixed `setPrinter()` not tracking cache keys. Keys stored by `setPrinter()` were never added to `qz.printer_keys`, so `clearCache()` and `qz:clear-cache` could not find and delete them.

### ✨ New Features

- **`smart.blade.php`** — Full interactive SmartPrint demo page at `/qz/smart`. Shows live connection status, available printers, PDF print form, ZPL/ESC·POS raw form, data-attribute button demos, and real-time event log.
- **`data-qz-auto-print` support** — Elements with this attribute now auto-print on page load, with optional `data-qz-delay` milliseconds.
- **Dual attribute support** — Both `data-qz-print` (new, documented) and `data-smart-print` (legacy) are now handled by `bind()`.
- **Complete public API** — All previously undocumented / missing methods added to `SmartPrint` return object: `isConnected()`, `connect()`, `disconnect()`, `getPrinters()`, `getCurrentPrinter()`, `showPrinterSwitcher()`, `printRaw()`, `printZPL()`, `printESC()`, `getQueue()`, `clearQueue()`, `getSettings()`, `updateSettings()`, `on()`, `off()`, `clearCache()`.
- **Global shorthand functions** — `smartPrint()`, `smartPrintZPL()`, `smartPrintESC()` registered on `window` for convenience.
- **`generateCertificatePublic()`** — HTTP endpoint to generate a certificate (disabled by default; enable with `QZ_ALLOW_PUBLIC_CERT_GENERATE=true`).
- **`testSign()`** — HTTP endpoint to verify the entire signing pipeline end-to-end.
- **Config keys `auto_generate_cert` and `allow_public_cert_generate`** added to `config/qz-tray.php` (were referenced in code but missing from the config file).
- **Printer cache key tracking** — `setPrinter()` now registers keys in `qz.printer_keys` so `clearCache()` and `qz:clear-cache` can clean them up reliably.
- **Full README** — A-to-Z documentation: installation, configuration, all routes, all JS APIs, print use cases (PDF, ZPL, ESC/POS, raw), event system, troubleshooting, FAQ, and upgrade guide.

### 🔧 Changed

- `composer.json` — Removed non-existent Facade alias from `extra.laravel.aliases`.
- `config/qz-tray.php` — Default WebSocket port changed from `8182` to `8181` (QZ Tray default).
- `QzSecurityController` — Removed `use Mpdf\Mpdf` import; `testPdf()` now uses `barryvdh/laravel-dompdf` if available, otherwise streams HTML.
- `smart-print.js` — Auto-reconnect interval changed from 5 s to 10 s to reduce noise.
- `InstallQzTray` — Replaced unreliable `getApplication()->has()` command check with straightforward `callSilent()` + error handling.

### 📦 Compatibility

| | Version |
|---|---|
| PHP | 8.1, 8.2, 8.3 |
| Laravel | 10.x, 11.x, 12.x |
| QZ Tray | 2.x |
| ext-openssl | Required |

---

## [v0.1.10] — 2026-01-27

> Smart print library overhaul and view improvements.

### Changed
- `smart-print.js` — Major rewrite (note: contained duplicate function body bug — fixed in v1.0.0)
- `smart-print.min.js` — Minified version updated
- `smart-print-old.js` — Previous version archived
- `QzSecurityController.php` — Additional endpoints added
- `routes/web.php` — New routes registered
- `resources/views/smart.blade.php` — View stub added (was empty/missing — completed in v1.0.0)
- `resources/views/example.blade.php` — Example view added
- `resources/css/smart-print.css` — Stylesheet for SmartPrint UI
- `folder_structure.txt` — Documentation file updated

### Known Issues (fixed in v1.0.0)
- `smart.blade.php` was a stub and threw `ViewNotFoundException`
- `smart-print.js` had the entire function body duplicated

---

## [v0.1.9] — 2026-01-24

> Added jsrsasign library and fixed default view.

### Added
- `resources/js/sample/jsrsasign-all-min.js` — RSA signing library for client-side use
- `resources/views/default.blade.php` — Default test page view

### Changed
- `src/QzTrayServiceProvider.php` — View registration improvements

---

## [v0.1.8] — 2026-01-24

> Route and service provider improvements.

### Changed
- `routes/web.php` — Route cleanup and additions
- `src/Console/Commands/InstallQzTray.php` — Installer command improvements
- `src/QzTrayServiceProvider.php` — Boot method refinements
- `README.md` — Updated documentation

---

## [v0.1.7] — 2026-01-24

> Working demo assets bundled.

### Added
- `resources/assets/` — Sample files: ZPL, ESC/POS, EPL, PDF, FGL, SBPL, PGL samples
- `resources/assets/signing/` — Reference signing implementations in 20+ languages (PHP, Python, Go, Java, Node.js, Ruby, Vue, TypeScript, etc.)
- `resources/assets/img/` — Sample images for print testing
- `resources/css/` — Bootstrap, Font Awesome, custom styles
- `resources/fonts/` — Font Awesome fonts
- `resources/js/qz-tray.js` — QZ Tray WebSocket library
- `resources/js/sample/` — Polyfill and sample JavaScript files
- `resources/sample.html` — Official QZ Tray demo page
- `resources/views/default.blade.php` — Default Blade view wrapping demo
- `resources/views/smart.blade.php` — SmartPrint Blade view stub

### Changed
- `routes/web.php` — Routes updated to match new controllers
- `src/Http/Controllers/QzSecurityController.php` — Major controller update

---

## [v0.1.6] — 2026-01-23

> Controller route method updates.

### Changed
- `routes/web.php` — Additional routes added
- `src/Http/Controllers/QzSecurityController.php` — Controller methods aligned with routes

---

## [v0.1.5] — 2026-01-23

> Full controller and configuration overhaul.

### Added
- `src/Console/Commands/GenerateCertificate.php` — Certificate generation command
- `src/Console/Commands/InstallQzTray.php` — Package installer command

### Changed
- `config/qz-tray.php` — Full configuration rewrite with all settings
- `routes/web.php` — All 19 routes registered
- `src/Http/Controllers/QzSecurityController.php` — All controller methods stubbed
- `src/QzTrayServiceProvider.php` — Service provider updated to register all commands and routes
- `README.md` — Documentation updated

### Removed
- `resources/views/test.blade.php` — Renamed / replaced

---

## [v0.1.4] — 2026-01-22  *(duplicate tag — same commit as v0.1.3)*

> Tag alias for v0.1.3.

---

## [v0.1.3] — 2026-01-22

> Stability improvements and route-to-controller wiring.

### Changed
- `routes/web.php` — All routes wired to controller methods
- `src/Http/Controllers/QzSecurityController.php` — Controller stubs fleshed out
- General stability pass on the signing pipeline

---

## [v.0.1.4] — 2026-01-22  *(legacy malformed tag)*

> Internal testing tag. Same commit as v0.1.3. Superseded by v0.1.4.

---

## [v.0.1.3] — 2026-01-14

> Testing and signing development.

### Added
- Test mode endpoints

### Changed
- Signing logic improvements
- Initial signing endpoint development

---

## [v0.1.2] — 2026-01-14

> PHP 8.1+ compatibility.

### Fixed
- PHP 8.0/8.1 compatibility: `openssl_free_key()` deprecation warnings suppressed
- Strict type handling improvements in certificate generation

### Changed
- `src/Console/Commands/GenerateCertificate.php` — PHP 8 safe
- `src/QzTrayServiceProvider.php` — PHP 8 safe

---

## [v0.1.1] — 2026-01-13

> Laravel 12 support and composer update.

### Added
- Laravel 12.x support in `composer.json` require constraints

### Changed
- `composer.json` — `illuminate/support`, `illuminate/database`, `illuminate/routing` constraints updated to `^10.0|^11.0|^12.0`
- `README.md` — Initial documentation stub

---

## [v0.1.0] — 2026-01-13 🌱 Initial Release

> First public release of the Laravel QZ Tray package.

### Added
- `QzTrayServiceProvider` — Service provider with auto-discovery
- `QzSecurityController` — Core controller with `certificate()` and `sign()` endpoints
- `GenerateCertificate` artisan command — `php artisan qz:generate-certificate`
- `InstallQzTray` artisan command — `php artisan qz:install`
- `ClearQzCache` artisan command — `php artisan qz:clear-cache`
- `config/qz-tray.php` — Package configuration
- `database/migrations/` — `qz_print_jobs` table migration
- `resources/js/smart-print.js` — SmartPrint browser library
- `resources/js/smart-print.min.js` — Minified version
- `resources/js/adapters/zpl.js` — ZPL label helper
- `resources/js/adapters/escpos.js` — ESC/POS thermal helper
- `resources/js/adapters/raw-print.js` — Raw print helper
- `resources/js/printer-status.js` — Printer status widget
- `resources/js/printer-switcher.js` — Printer picker widget
- `routes/web.php` — Package routes under `/qz` prefix
- Auto-discovery via `extra.laravel.providers` in `composer.json`
- SHA512 request signing between browser and QZ Tray
- Per-page and global printer memory via `localStorage`
- Offline print queue with `localStorage` buffering and auto-retry
- Browser fallback (opens print dialog) when QZ Tray is offline
- `Ctrl+Shift+P` hotkey to open printer switcher
- Auto-reconnect every 10 seconds if connection drops

---

## Version Summary Table

| Version | Date | Key Change | Stability |
|---------|------|-----------|-----------|
| **v1.0.0** | 2026-05-19 | Full bug-fix pass + smart view + docs | ✅ **Stable** |
| v0.1.10 | 2026-01-27 | smart-print.js rewrite, new views | ⚠️ Has bugs |
| v0.1.9 | 2026-01-24 | jsrsasign added, default view fixed | ⚠️ Has bugs |
| v0.1.8 | 2026-01-24 | Route + SP improvements | ⚠️ Has bugs |
| v0.1.7 | 2026-01-24 | All demo assets bundled | ⚠️ Has bugs |
| v0.1.6 | 2026-01-23 | Controller route method updates | ⚠️ Has bugs |
| v0.1.5 | 2026-01-23 | Full controller + config overhaul | ⚠️ Has bugs |
| v0.1.4 | 2026-01-22 | Duplicate tag of v0.1.3 | ⚠️ Has bugs |
| v0.1.3 | 2026-01-22 | Stability + route wiring | ⚠️ Has bugs |
| v0.1.2 | 2026-01-14 | PHP 8.1+ compatibility | ⚠️ Has bugs |
| v0.1.1 | 2026-01-13 | Laravel 12 support | ⚠️ Has bugs |
| v0.1.0 | 2026-01-13 | Initial release | ⚠️ Has bugs |

---

## Upgrade to v1.0.0

```bash
composer update bitdreamit/laravel-qz-tray

php artisan vendor:publish --tag=qz-config  --force
php artisan vendor:publish --tag=qz-assets  --force
php artisan vendor:publish --tag=qz-blade   --force

php artisan qz:generate-certificate --force

php artisan migrate
```

Update any `data-smart-print` attributes to `data-qz-print` (old attribute still works but is deprecated):

```html
<!-- Before -->
<button data-smart-print="/invoice.pdf">Print</button>

<!-- After -->
<button data-qz-print="/invoice.pdf">Print</button>
```

---

*Maintained by [Bit Dream IT](https://bitdreamit.com) · MIT License*
