# Laravel QZ Tray — Bug Audit & Fix Report

**Repository:** `bitdreamit/laravel-qz-tray`
**Audit date:** 2026-07-12
**Files reviewed:** 13 PHP files, 7 JS files, 3 Blade views, 2 route files, 1 config
**Result:** 18 bugs identified and fixed across 12 files

---

## Summary

| Severity | Count | Categories |
|----------|-------|------------|
| Critical | 3 | Security (XSS), dead routes, unsafe navigation |
| High | 6 | CSRF, race conditions, missing DB logging, missing file serving |
| Medium | 6 | Config issues, missing validation, await on sync fns |
| Low | 3 | Dead code, return types, error message handling |

All JavaScript files pass `node --check`. PHP files were manually verified (PHP CLI not available in the audit environment, but brace/parenthesis balance and logic were confirmed by hand).

---

## Critical Bugs

### BUG-01 — XSS in SmartPrint demo page (Critical / Security)

**File:** `resources/views/smart.blade.php`
**Function:** `renderPrinters()`

**Problem:** Printer names returned from the OS via QZ Tray were injected into the DOM using `innerHTML` and into an inline `onclick` handler via string concatenation. A printer name containing `<img onerror=...>`, `</script>`, or even a stray `'` could execute arbitrary JavaScript in the user's session.

```js
// VULNERABLE — before
li.innerHTML = '<span>' + p + '</span>' +
    '<button ... onclick="SmartPrint.setPrinter(\'' + p.replace(/'/g, "\\'") + '\')...">Use</button>';
```

Only single quotes were escaped; `<`, `>`, `"`, and `</script>` sequences were all unescaped.

**Fix:** Rewrote `renderPrinters()` to use the DOM API (`document.createElement`, `textContent`, `addEventListener`, `dataset`) so the printer name is never parsed as HTML. Added an `escapeHtml()` helper for any future string-interpolated output.

---

### BUG-02 — API routes file is dead code (Critical)

**File:** `src/QzTrayServiceProvider.php`, `routes/api.php`

**Problem:** `routes/api.php` existed and defined Sanctum-protected printer/print endpoints, but the service provider only loaded `routes/web.php` via `loadRoutesFrom()`. The entire `api.php` file was unreachable dead code — users who followed the README's API documentation would get 404s.

**Fix:**
- Added an opt-in `config('qz-tray.routes.api.enabled')` flag in the service provider's `boot()` to load `routes/api.php` only when explicitly enabled.
- Updated `routes/api.php` to read its prefix and middleware from config instead of hardcoding them.
- Added a `return;` guard at the top of `api.php` so it is safe even if loaded directly.
- Added the `api` sub-config block to `config/qz-tray.php` with `QZ_API_ENABLED` env var.

---

### BUG-03 — `window.location.assign('qz:launch')` navigates away (Critical / UX)

**File:** `resources/js/smart-print.js`
**Function:** `connectQZ()`

**Problem:** When the WebSocket connection failed, the retry path called `window.location.assign('qz:launch')` to trigger the QZ Tray protocol handler. On machines where QZ Tray is **not** installed (the most common reason for a connection failure), this navigates the current tab to an unknown protocol URL, producing a browser error page like *"The address wasn't understood"* — the user loses their current page.

**Fix:** Replaced with a `launchQZProtocol()` helper that creates a hidden `<iframe>` with `src="qz:launch"`. The iframe invokes the protocol handler without navigating the parent page. If no handler is registered, the iframe fails silently and is cleaned up after 2 seconds.

---

## High-Severity Bugs

### BUG-04 — Throttle config defined but never applied (High)

**File:** `routes/web.php`, `config/qz-tray.php`

**Problem:** The config defined `'throttle' => '60,1'` under `routes`, but `routes/web.php` never read this value. All QZ endpoints (including the CPU-intensive `sign` endpoint) were unthrottled, making them easy abuse targets.

**Fix:** `routes/web.php` now reads `config['throttle']` and appends `throttle:{value}` to the middleware stack when non-empty.

---

### BUG-05 — `print()` endpoint never persisted to database (High)

**File:** `src/Http/Controllers/QzSecurityController.php`
**Function:** `print()`

**Problem:** The package ships a migration creating `qz_print_jobs` (with `tenant_id`, `user_id`, `printer_name`, `status`, `metadata`, etc.), and the README documents "Database — Print Job Logging". But the `print()` controller method only wrote to the log file when `logging.enabled` was true — it never inserted a row into `qz_print_jobs`. The migration was effectively dead.

**Fix:**
- Added a `Schema::hasTable('qz_print_jobs')` guard so the insert is skipped gracefully on apps that haven't run migrations.
- Insert the print job with `printer_name`, `document_url`, `document_type`, `copies`, `status='pending'`, `metadata`, and the authenticated user's ID (via morph columns — see BUG-12).
- Returns `db_logged: true/false` in the JSON response so the frontend knows whether the job was persisted.
- Wrapped in try/catch so a DB failure degrades to log-only instead of 500-ing the print request.

---

### BUG-06 — `installer()` never served the bundled installers (High)

**File:** `src/Http/Controllers/QzSecurityController.php`
**Function:** `installer()`

**Problem:** The package bundles 3 real installer files in `resources/installers/` (`qz-tray-windows.exe`, `qz-tray-linux.deb`, `qz-tray-macos.pkg`), the `QzTrayServiceProvider` publishes them to `public/vendor/qz-tray/installers/`, and the config maps each OS to a filename. But the `installer()` controller method ignored all of this and always returned JSON with `download_url: 'https://qz.io/download'`. Users who hit `/qz/installer/windows` expecting a file download got a JSON object instead.

**Fix:**
- The method now checks `config("qz-tray.installers.{$os}")` and `public_path(...)`. If the published file exists, it returns a `BinaryFileResponse` via `response()->download()` with the correct MIME type per OS.
- Falls back to the qz.io JSON redirect when the file is not published (with a helpful note telling the user to run `php artisan vendor:publish --tag=qz-installers`).
- Updated return type to `BinaryFileResponse|JsonResponse`.
- `InstallQzTray` command now also publishes the `qz-installers` tag during `qz:install`.

---

### BUG-07 — PrinterSwitcher opens then immediately closes (High / UX)

**File:** `resources/js/printer-switcher.js`
**Function:** `setupEventListeners()`

**Problem:** A "close on click outside" listener was bound to `document` on the `click` event. When a user clicked the opener button:
1. The button's click handler ran `switcher.toggle()` → `show()` → `isVisible = true`.
2. The same click event bubbled to `document`.
3. The document handler saw `isVisible === true` and `e.target` (the button) was not inside the switcher container → called `hide()`.

Net effect: the switcher flashed open and closed in the same tick. Unusable.

**Fix:**
- Switched the document listener from `click` to `mousedown`, which fires before the opener's `click` handler. Now the document handler runs while `isVisible` is still `false`, so it does not hide.
- Added an additional guard: if the mousedown target is the opener element itself (detected via `dataset.qzSwitcher`), skip hiding so the opener's click handler can toggle normally.

---

### BUG-08 — `bind()` did not preventDefault on data-qz-print clicks (High)

**File:** `resources/js/smart-print.js`
**Function:** `bind()`

**Problem:** The delegated click handler read `data-qz-print` attributes and enqueued a print job, but never called `e.preventDefault()`. A `<button data-qz-print="...">` placed inside a `<form>` would submit the form, potentially navigating away from the page.

**Fix:** Added `e.preventDefault()` immediately after the `closest()` match.

---

### BUG-09 — `sign()` accepted non-string data (High / Robustness)

**File:** `src/Http/Controllers/QzSecurityController.php`
**Function:** `sign()`

**Problem:** The validation was `if (! $data)` — this means `0`, `false`, `''`, and `null` were all rejected, but an array or object passed as `data` would be silently cast to "Array" by `openssl_sign`, producing a signature of the literal string "Array". Any non-string scalar (e.g., `true`) would also sign an unexpected value.

**Fix:** Changed validation to `if (! is_string($data) || $data === '')` so only actual strings are signed.

---

## Medium-Severity Bugs

### BUG-10 — `OPENSSL_KEYTYPE_RSA` constant in config breaks `config:cache` (Medium)

**File:** `config/qz-tray.php`

**Problem:** The config used `'key_type' => OPENSSL_KEYTYPE_RSA`. When a user runs `php artisan config:cache` in an environment where the openssl extension is not loaded (common in CI pipelines and some Docker build stages), the config file is evaluated and the constant lookup throws a fatal error, breaking the entire cache command.

**Fix:** Replaced with the integer literal `0` (since `OPENSSL_KEYTYPE_RSA === 0`) and added a comment explaining the equivalence. The `GenerateCertificate` command still falls back to `OPENSSL_KEYTYPE_RSA` at runtime when the config value is unset, so no behavior changes.

---

### BUG-11 — `generateCertificatePublic()` did not check CSR result (Medium)

**File:** `src/Http/Controllers/QzSecurityController.php`
**Function:** `generateCertificatePublic()`

**Problem:** `openssl_csr_new()` can return `false` on failure (e.g., malformed subject). The code passed the result directly to `openssl_csr_sign()` without checking, which would then either error out or return `false`, producing the generic "Failed to create certificate" message with no diagnostic value.

**Fix:** Added an explicit `if (! $csr)` check returning a 500 with the message "Failed to create CSR".

---

### BUG-12 — Migration `foreignId('user_id')->constrained()` breaks UUID apps (Medium)

**File:** `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php`

**Problem:** `foreignId('user_id')->constrained()` creates an `unsignedBigInteger` column and a FK to `users(id)`. On apps that use UUID or ULID primary keys for their User model (very common in modern Laravel apps), the FK constraint fails to create and the migration throws.

**Fix (superseded — see BUG-27):** Replaced with `nullableMorphs('user')`. ~~Works with any primary key type.~~ **This claim was wrong** — `nullableMorphs()` still hardcodes `user_id` as `unsignedBigInteger` unless the host app sets `Schema::defaultMorphKeyType('uuid')` globally (confirmed against Laravel's own issue tracker, laravel/framework#27659), which this package can't assume. It fixed the FK-crash symptom (no more `constrained()`, so no FK to fail) but not the underlying UUID-user-id problem BUG-12 claimed to solve. See **BUG-27** for the actual fix.
- Updated the index from `['user_id', 'status']` to `['user_id', 'user_type', 'status']`.
- Updated `QzSecurityController::print()` to populate both `user_id` and `user_type` (via `get_class($user)`).

---

### BUG-13 — `await` on synchronous `getCurrentPrinter()` (Medium)

**File:** `resources/js/printer-switcher.js`, `resources/js/printer-status.js`

**Problem:** Both files called `await window.SmartPrint.getCurrentPrinter()`, but `getCurrentPrinter` is a synchronous function that returns `state.currentPrinter` directly (not a Promise). `await` on a non-Promise simply resolves to the value, so the code "works" by accident — but it's misleading: readers assume the function is async, and if someone later changes `getCurrentPrinter` to return a non-thenable object with a `.then` property, the await would misbehave.

**Fix:** Removed the `await` in both call sites. Added comments noting that `getCurrentPrinter` is synchronous.

---

### BUG-14 — `zpl.js generateFromTemplate()` regex injection (Medium)

**File:** `resources/js/adapters/zpl.js`
**Function:** `generateFromTemplate()`

**Problem:** Placeholder replacement used `new RegExp('{{' + key + '}}', 'g')`. The curly braces `{` and `}` are regex quantifier syntax. While `{{key}}` happens to work in V8's regex engine, a key containing regex metacharacters like `.`, `*`, `+`, `?`, `(`, `)`, `|`, `\`, `[`, `]`, `$`, or `^` would either throw a syntax error or match unintended patterns. A template key like `item.price` would be interpreted as "any character" between the braces.

**Fix:** Added an `escapeRegExp()` helper that escapes all regex metacharacters. Applied it to the placeholder before constructing the `RegExp`. Also coerced the replacement value to `String()` to avoid `TypeError` if a number or boolean is passed.

---

### BUG-15 — `cert_ttl` config ignored (Medium)

**File:** `src/Http/Controllers/QzSecurityController.php`
**Function:** `certificate()`

**Problem:** The config defined `'cert_ttl' => 3600` (seconds the browser may cache the certificate), but the `certificate()` method always sent `Cache-Control: no-store, no-cache, must-revalidate`. The config value was dead.

**Fix:** The method now reads `config('qz-tray.cert_ttl', 0)`. When `> 0`, it sends `Cache-Control: public, max-age={ttl}`. When `0`, it falls back to the original no-cache behavior.

---

## Low-Severity Bugs

### BUG-16 — Hotkey only matched uppercase 'P' (Low)

**File:** `resources/js/smart-print.js`, `resources/js/printer-switcher.js`

**Problem:** The Ctrl+Shift+P hotkey checked `e.key === 'P'` (uppercase only). While Shift is held on standard layouts, `e.key` is `'P'`, but on some keyboard layouts and when Caps Lock is also engaged, `e.key` could be `'p'`. The hotkey would silently fail.

**Fix:** Accept both `'P'` and `'p'`: `e.key === 'P' || e.key === 'p'`.

---

### BUG-17 — `ClearQzCache::handle()` missing return type (Low)

**File:** `src/Console/Commands/ClearQzCache.php`

**Problem:** The `handle()` method had no return type declaration and returned the raw integer `0`. The other commands (`InstallQzTray`, `GenerateCertificate`) use `: int` and `return self::SUCCESS`.

**Fix:** Added `: int` return type and changed `return 0;` to `return self::SUCCESS;` for consistency.

---

### BUG-18 — `openssl_error_string()` can return `false` (Low)

**File:** `src/Console/Commands/GenerateCertificate.php`

**Problem:** Three error paths concatenated `openssl_error_string()` directly into the error message: `'Failed to ...: ' . openssl_error_string()`. When there is no pending OpenSSL error, `openssl_error_string()` returns `false`, and PHP coerces `false` to the empty string — producing the confusing message "Failed to generate private key: " with nothing after the colon.

**Fix:** All three sites now use `$err = openssl_error_string() ?: 'unknown error';` before concatenation, so the message always has a meaningful suffix.

---

## Files Changed

| File | Bugs Fixed |
|------|-----------|
| `src/Http/Controllers/QzSecurityController.php` | BUG-05, BUG-06, BUG-09, BUG-11, BUG-15 |
| `src/QzTrayServiceProvider.php` | BUG-02 |
| `routes/web.php` | BUG-04 |
| `routes/api.php` | BUG-02 |
| `config/qz-tray.php` | BUG-02, BUG-10 |
| `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php` | BUG-12 |
| `resources/js/smart-print.js` | BUG-03, BUG-08, BUG-16 |
| `resources/js/printer-switcher.js` | BUG-07, BUG-13, BUG-16 |
| `resources/js/printer-status.js` | BUG-13 |
| `resources/js/adapters/zpl.js` | BUG-14 |
| `resources/views/smart.blade.php` | BUG-01 |
| `src/Console/Commands/InstallQzTray.php` | BUG-06 (publish installers) |
| `src/Console/Commands/GenerateCertificate.php` | BUG-18 |
| `src/Console/Commands/ClearQzCache.php` | BUG-17 |

---

## Verification

- **JavaScript:** All 7 JS files pass `node --check` (Node.js v24.18.0).
- **PHP:** Manually verified brace/parenthesis balance and control-flow structure for all 13 PHP files. (PHP CLI was not available in the audit environment.)
- **No breaking API changes:** All public method signatures, route names, and config keys remain backward-compatible. New behavior is opt-in (e.g., API routes require `QZ_API_ENABLED=true`).

---

## Recommendations (not fixed)

These are design observations, not bugs:

1. **`smart-print-old.js`** is obsolete dead code and should be deleted from the package.
2. **`stracture.txt`** has a typo in the filename (should be `structure.txt`). Consider deleting both `stracture.txt` and `folder_structure.txt` since they duplicate README content.
3. **`README_OLD.md`** should be removed from the published package.
4. **`sign-message.*`** signing samples in 20+ languages are bundled with the package but only used for reference. Consider moving them to a separate docs repo to reduce the published package size.
5. **The `setPrinter`, `clearCache`, and `print` POST endpoints** require a CSRF token (via `web` middleware), but `smart-print.js` never calls them — it uses `localStorage` for printer memory instead. Either wire the JS to use the server-side endpoints, or document them as optional/backend-only.
6. **The `default.blade.php` (3464 lines)** is the full QZ Tray demo page and loads jQuery 1.11.3 (from 2015, has known XSS vulnerabilities). Consider updating or removing it.
7. **`example.blade.php`** references `route('receipts.show', ['id' => 456])` which will throw `RouteNotFoundException` on apps that don't define that route. Guard with `@if(Route::has('receipts.show'))` or remove.

---

# Addendum — v1.1.0 Audit (2026-07-22)

**Trigger:** requested UUID device identity, multi-user/single-user/direct/queue print-mode review, and a QZ Tray library version check.
**Files reviewed:** all files from the 2026-07-12 audit, re-checked after the fixes below were applied.
**Result:** 1 critical cross-user data leak, 3 high-severity dead/broken-contract bugs, 1 outdated pinned dependency.

| Severity | Count | Categories |
|----------|-------|------------|
| Critical | 1 | Cross-user/cross-workstation data leak |
| High | 3 | Broken public API contract, unreachable queue endpoints, orphaned promise |
| Low | 1 | Outdated pinned dependency |

## BUG-19 — Server-side printer memory leaked across users/workstations (Critical)

**File:** `src/Http/Controllers/QzSecurityController.php`
**Functions:** `setPrinter()`, `getPrinter()`

**Problem:** `setPrinter()` wrote the chosen printer to `Cache::put('qz.printer.' . $path, ...)` — a single, **identity-less** key shared by every visitor to that URL — as a fallback beneath the per-session value. `getPrinter()` read `session() ?? Cache::get(...) ?? default`. Recommendation #5 in the original audit noted `smart-print.js` never called these endpoints (it used `localStorage` only), so this was dormant in the stock JS flow — but the routes are documented as a public API, and this package's target use case (multiple shared lab/kiosk workstations printing through the same central web app) is exactly the scenario where a developer would reach for server-side printer memory instead of per-browser `localStorage`. Concretely: Workstation A sets "Label Printer" for `/orders/5`. Workstation B — different PC, different physical printer, first-ever visit to that path, no session value yet — would silently be handed A's printer as its own.

**Fix:**
- New `qz_printer_preferences` table (migration `2026_07_22_000000_create_qz_printer_preferences_table.php`), unique on `(identity_type, identity_value, path)`.
- `resolveIdentities()` computes every identity present on the request: `device` (from an `X-Device-Id: <uuid>` header/param), `user` (authenticated user id), `session` (Laravel session id) — never falls through to an identity-less key.
- `setPrinter()` writes one row per identity present on the request, so the preference is correctly retrievable however many identities apply.
- `getPrinter()` reads in the order defined by the new `qz-tray.identity_priority` config (default `device → user → session`), returning `config('qz-tray.default_printer')` only when **no** identity has a stored row for that path — never another identity's value.
- `clearCache()` now deletes the requester's own preference rows (by identity) plus best-effort cleanup of any pre-1.1 Cache/session keys.

---

## BUG-20 — `SmartPrint.print()` never returned the job promise (High / Broken API contract)

**File:** `resources/js/smart-print.js`
**Function:** public `print()`

**Problem:** The README's "Programmatic API Usage" section documents `const jobId = await SmartPrint.print(url, options);` and `onComplete`/`onError` callbacks in the options object. Neither worked: `enqueue()` returned nothing (undefined), and `print()`'s branches called `enqueue(job)` without a `return`, so the promise chain was severed twice over. `onComplete`/`onError` were never invoked anywhere in the file.

**Fix:**
- `enqueue()` now creates a `Promise` per job (idempotently — see BUG-22), stores `_resolve`/`_reject` on the job object, and returns it.
- `print()`, `printRaw()`, `printZPL()`, `printESC()` all `return enqueue(...)`.
- `printQZ()` settles the promise and invokes `job.onComplete(job)` / `job.onError(err, job)` on every terminal branch (success, each validation failure, unknown type via fallback, and the `qz.print()` catch block).
- Job IDs are real UUIDs (`crypto.randomUUID()`, with a template-based fallback for older embedded browsers) generated once per job and echoed back in the resolved value: `{ jobId, success }`.

---

## BUG-21 — `GET /qz/jobs` and `DELETE /qz/jobs/{id}` were hardcoded stubs (High)

**File:** `src/Http/Controllers/QzSecurityController.php`
**Functions:** `jobs()`, `cancelJob()`

**Problem:** `jobs()` always returned `{ jobs: [] }` and `cancelJob($id)` always returned `{ success: true }` regardless of whether `$id` existed — neither queried `qz_print_jobs`. Combined with BUG-05's fix (which did start writing rows) and the fact that `print()` minted its own `uniqid()` server-side that no client code ever received, the queue-management endpoints were structurally unreachable: nothing on the client ever had a job id to cancel, and the "list" endpoint couldn't have shown anything real even if it queried the table.

**Fix:**
- `print()` now accepts an optional client-supplied `job_id` (validated as `uuid`) and uses it as the row's `uuid` if present, falling back to a server-generated `Str::uuid()` for direct API callers. `smart-print.js` sends its client-generated job id here via a new `logPrintJob()` call after each successful `qz.print()`, so the id returned to `await SmartPrint.print()` is the same id that identifies the row.
- `jobs()` now queries `qz_print_jobs` for `pending`/`processing` rows, scoped to the requester's `user_id`+`user_type` if authenticated, else their `X-Device-Id` — so one workstation's queue view can't show another's jobs.
- `cancelJob($id)` looks up the row by `uuid`, returns 404 if not found, 409 if already in a terminal state, and otherwise sets `status = 'cancelled'`.
- Migration adds `uuid` (unique) and `device_id` columns to `qz_print_jobs`, plus a `(device_id, status)` index for the queue lookup above.

---

## BUG-22 — Printer-selection modal orphaned the original job's promise (High)

**File:** `resources/js/smart-print.js`
**Function:** `openPrinterModal()`

**Problem:** When `printQZ()` had no printer selected, it opened the modal and returned (with BUG-20's fix, leaving the job's promise pending). The modal's button handler then called `enqueue({ ...jobToQueue, printer })` — spreading the job into a **new object** with a **new** `_resolve`/`_reject` pair once BUG-20 introduced them. Any caller doing `await SmartPrint.print(...)` before a printer had ever been chosen would hang forever: the promise it was holding was never the one that actually got printed.

**Fix:** `enqueue()` is idempotent on an object that already carries a `_promise` — it just re-pushes the same job onto the queue rather than minting a second promise. The modal now mutates `jobToQueue.printer` and re-enqueues the same reference. Cancelling the modal (button or backdrop click) now rejects the pending promise with `"Print cancelled: no printer selected"` instead of leaving it pending indefinitely.

---

## BUG-23 — Pinned QZ Tray client library was one release behind (Low)

**Files:** `README.md`, `src/Console/Commands/InstallQzTray.php`, `resources/views/smart.blade.php`

**Problem:** All three shipped `<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js">`. Upstream (`qzind/tray`) released `2.2.6` in April 2026: race-condition fix in the websocket connection, better locking/concurrency for hardware I/O (serial/network/files/USB), and a Windows SYSTEM-user install fix.

**Fix:** Bumped the pinned CDN version to `2.2.6` in all three files. No package code changes required — this is a client-library version only, and 2.2.x is API-compatible per QZ Tray's own versioning.

---

## BUG-24 — `tenant_id` hardcoded to bigint-only and never actually populated (Medium)

**Files:** `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php`, `src/Http/Controllers/QzSecurityController.php`, `config/qz-tray.php`, `resources/js/smart-print.js`

**Problem:** `qz_print_jobs.tenant_id` was declared `unsignedBigInteger` — the exact same class of bug BUG-12 fixed for `user_id` (this package is installed across multiple client projects whose "project"/"tenant" table's primary key is bigint in some apps, UUID in others; a bigint-only column would reject or truncate a UUID project id). On top of that, `print()` hardcoded `'tenant_id' => null` — the column was never actually written to by any code path, so even bigint-keyed projects got no tenant scoping.

**Fix:**
- `tenant_id` column changed from `unsignedBigInteger` to `string` (nullable) — holds either a numeric id or a UUID without a schema change, mirroring the `nullableMorphs` approach already used for `user_id`. Added a `(tenant_id, status)` index for per-project queue queries.
- `print()` now accepts `tenant_id` (or `project_id`, same column, either name accepted) in the request, validated as either an unsigned integer or a UUID via a new `isBigintOrUuid()` helper, and stores it.
- New `qz-tray.tenant_id_resolver` config: an optional `callable(Request): string|int|null` for multi-tenant host apps that want every job auto-tagged with the current tenant without passing it at every call site — only used when the request didn't supply `tenant_id`/`project_id` explicitly.
- `jobs()` (queue listing) additively filters by `tenant_id` when present, on top of the existing user/device scoping from BUG-19/21 — narrows the queue further when a shared device/user identity spans more than one project's data.
- `smart-print.js`'s `logPrintJob()` forwards `job.tenantId`/`job.projectId` (per-job) or `window.QZ_CONFIG.tenantId`/`.projectId` (page-wide default) as `tenant_id` on the print-log request.

---

---

## BUG-25 — Redundant `id` + `uuid` column pair; no way to choose a uuid primary key (v1.1.1)

**Files:** `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php`, `src/Http/Controllers/QzSecurityController.php`, `config/qz-tray.php`

**Problem:** BUG-21's fix (v1.1.0) added a `uuid` column alongside the existing auto-increment `id`, because `id` was never meant to be client-facing (BUG-21's own comment: *"Never expose the auto-increment id to the client — it leaks row counts and is guessable"*). That's correct for installs that want a sequential PK, but it means every install pays for two indexed id columns even when the sequential `id` is never used for anything, and there was no way to just make the primary key itself a uuid.

**Fix:** `qz-tray.id_type` config (`'uuid'` default | `'bigint'`), read at migration time. `id` is now either `$table->uuid('id')->primary()` or `$table->id()` — never both a bigint and a separate uuid column. `print()`, `jobs()`, and `cancelJob()` all operate on `id` directly; in uuid mode the client-generated job id becomes the row's PK with no translation step, in bigint mode the client's id is accepted but the response substitutes the real auto-increment value once the row exists.

**Note:** this is a breaking schema change relative to v1.1.0's migration (see `RELEASE_NOTES.md` upgrade notes) — not additive like BUG-19 through BUG-24.

---

---

## BUG-26 — Vendored `qz-tray.js` still shipped genuine 2.2.5 code despite the 2.2.6 CDN bump (v1.1.4)

**File:** `resources/js/qz-tray.js`

**Problem:** BUG-23 (v1.1.0) bumped the pinned CDN version from `2.2.5` to `2.2.6` in `README.md`, `InstallQzTray.php`, and `smart.blade.php` — but missed that the package also **vendors a full local copy** of the actual QZ Tray library at `resources/js/qz-tray.js` (2,860 lines, published to `public/vendor/qz-tray/js/qz-tray.js`), used by `default.blade.php` and `example.blade.php` as a self-hosted alternative to the CDN. That file's own `@version` header and internal `VERSION` constant still said `2.2.5`, and — more importantly — it was still genuinely running 2.2.5 code (the websocket race-condition fix, hardware I/O locking/concurrency improvements, and Windows SYSTEM-user install fix from 2.2.6 were absent), not just an outdated label.

**Verification before fixing:** diffed the vendored file against a freshly-downloaded genuine upstream `qz-tray@2.2.5` from the npm registry — byte-identical, confirming Bit Dream IT had not customized it, so a full replacement was safe.

**Fix:** Replaced `resources/js/qz-tray.js` with the genuine upstream `qz-tray@2.2.6` source (also from npm), not just an edited version string. `@version`/`VERSION` now correctly read `2.2.6` and match the actual code running.

---

## Files Changed (v1.1.0 – v1.1.1)

| File | Bugs Fixed |
|------|-----------|
| `src/Http/Controllers/QzSecurityController.php` | BUG-19, BUG-21, BUG-24, BUG-25 |
| `config/qz-tray.php` | BUG-19, BUG-24, BUG-25 |
| `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php` | BUG-21, BUG-24, BUG-25 |
| `database/migrations/2026_07_22_000000_create_qz_printer_preferences_table.php` (new) | BUG-19 |
| `resources/js/smart-print.js` | BUG-19, BUG-20, BUG-21, BUG-22, BUG-24 |
| `README.md`, `src/Console/Commands/InstallQzTray.php`, `resources/views/smart.blade.php` | BUG-23 |

## New Migration Required

```bash
php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider" --tag=qz-migrations --force
php artisan migrate
```

## Recommendations (not fixed)

1. **`printer-switcher.js`/`printer-status.js`** still only read `SmartPrint.getCurrentPrinter()`/localStorage — consider surfacing `getDeviceId()` in the status widget for on-screen confirmation of which workstation identity is active (useful for lab-PC troubleshooting).
2. **`identity_priority` = `device` first** is the right default for shared/kiosk workstations (your Mirth Connect PC → multi-analyzer topology) but wrong for apps where a person's printer choice should follow them between machines — flip the config to `['user', 'device', 'session']` for that case.
3. **`qz_printer_preferences` rows are never pruned.** `printer_cache_duration` config still exists but nothing acts on it now that Cache TTL isn't the storage mechanism. Consider a scheduled command (`qz:prune-preferences --older-than=90`) if this matters for your retention policy.
4. **`qz_printer_preferences` is not tenant-scoped.** Only `qz_print_jobs` got a `tenant_id` column in this pass. If two projects sharing one database also need isolated printer *memory* (not just job history), the same `tenant_id` (string, bigint-or-uuid) column should be added to `qz_printer_preferences` and folded into `resolveIdentities()`.

*(Recommendations 1–4 above were later implemented — see the v1.1.2 entry in `RELEASE_NOTES.md`.)*

---

## BUG-27 — `nullableMorphs('user')` still hardcoded `user_id` as bigint; BUG-12 didn't actually fix what it claimed to (Medium)

**File:** `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php`

**Trigger:** reviewing a proposed rewrite of the printer-preferences/print-jobs migrations that tied `tenant_id`'s column type to `id_type` (this table's own PK config) — that particular idea was rejected (see below), but checking it required re-verifying `nullableMorphs()`'s actual behavior, which turned up this.

**Problem:** BUG-12 replaced `foreignId('user_id')->constrained()` with `nullableMorphs('user')` and claimed the result "works with any primary key type." That's false. Per Laravel's own issue tracker (`laravel/framework#27659`), `nullableMorphs()` unconditionally generates `user_id` as `unsignedBigInteger` — the only way to get a UUID-compatible morph column is `nullableUuidMorphs()`, and the only way to make `nullableMorphs()` itself uuid-shaped is a global `Schema::defaultMorphKeyType('uuid')` call in the *host* app, which this package has no business assuming or requiring. So any app with a UUID-keyed User model was still broken — BUG-12 fixed the FK-constraint crash (by removing `constrained()`, there's no FK left to fail), but not the UUID-user-id case its own description claimed to solve.

**Why the proposed fix (tying `user_id`'s type to `$usesUuid` / `id_type`) was rejected instead of adopted:** `id_type` controls this table's *own* primary key. A host app's User model PK type is a completely unrelated setting — an app could reasonably run `QZ_JOB_ID_TYPE=bigint` (this table's PK) while its own `users.id` is a UUID, or vice versa. Inferring one from the other is a modeling error, not a fix; it would just move the same class of bug to a different config combination instead of removing it. The same reasoning applies to `tenant_id`, which the proposed version also tied to `id_type` — rejected for the same reason, and additionally because a native `uuid` column (Postgres) or the `''`-for-no-tenant convention this package relies on elsewhere would break outright on a bigint tenant id being written into a `uuid`-typed column.

**Fix:** Removed `nullableMorphs('user')` entirely. `user_id` and `user_type` are now built manually as plain nullable strings — the same approach already used for `tenant_id` since BUG-24, and deliberately independent of `id_type`. `QzSecurityController::print()`'s insert now explicitly casts `(string) $user->getAuthIdentifier()`, matching the column type regardless of whether the host app's User model uses an int, UUID, or ULID key.
