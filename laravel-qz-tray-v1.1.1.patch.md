diff --git a/BUG_REPORT.md b/BUG_REPORT.md
index f25c80c..6ed1094 100644
--- a/BUG_REPORT.md
+++ b/BUG_REPORT.md
@@ -288,3 +288,138 @@ These are design observations, not bugs:
 5. **The `setPrinter`, `clearCache`, and `print` POST endpoints** require a CSRF token (via `web` middleware), but `smart-print.js` never calls them — it uses `localStorage` for printer memory instead. Either wire the JS to use the server-side endpoints, or document them as optional/backend-only.
 6. **The `default.blade.php` (3464 lines)** is the full QZ Tray demo page and loads jQuery 1.11.3 (from 2015, has known XSS vulnerabilities). Consider updating or removing it.
 7. **`example.blade.php`** references `route('receipts.show', ['id' => 456])` which will throw `RouteNotFoundException` on apps that don't define that route. Guard with `@if(Route::has('receipts.show'))` or remove.
+
+---
+
+# Addendum — v1.1.0 Audit (2026-07-22)
+
+**Trigger:** requested UUID device identity, multi-user/single-user/direct/queue print-mode review, and a QZ Tray library version check.
+**Files reviewed:** all files from the 2026-07-12 audit, re-checked after the fixes below were applied.
+**Result:** 1 critical cross-user data leak, 3 high-severity dead/broken-contract bugs, 1 outdated pinned dependency.
+
+| Severity | Count | Categories |
+|----------|-------|------------|
+| Critical | 1 | Cross-user/cross-workstation data leak |
+| High | 3 | Broken public API contract, unreachable queue endpoints, orphaned promise |
+| Low | 1 | Outdated pinned dependency |
+
+## BUG-19 — Server-side printer memory leaked across users/workstations (Critical)
+
+**File:** `src/Http/Controllers/QzSecurityController.php`
+**Functions:** `setPrinter()`, `getPrinter()`
+
+**Problem:** `setPrinter()` wrote the chosen printer to `Cache::put('qz.printer.' . $path, ...)` — a single, **identity-less** key shared by every visitor to that URL — as a fallback beneath the per-session value. `getPrinter()` read `session() ?? Cache::get(...) ?? default`. Recommendation #5 in the original audit noted `smart-print.js` never called these endpoints (it used `localStorage` only), so this was dormant in the stock JS flow — but the routes are documented as a public API, and this package's target use case (multiple shared lab/kiosk workstations printing through the same central web app) is exactly the scenario where a developer would reach for server-side printer memory instead of per-browser `localStorage`. Concretely: Workstation A sets "Label Printer" for `/orders/5`. Workstation B — different PC, different physical printer, first-ever visit to that path, no session value yet — would silently be handed A's printer as its own.
+
+**Fix:**
+- New `qz_printer_preferences` table (migration `2026_07_22_000000_create_qz_printer_preferences_table.php`), unique on `(identity_type, identity_value, path)`.
+- `resolveIdentities()` computes every identity present on the request: `device` (from an `X-Device-Id: <uuid>` header/param), `user` (authenticated user id), `session` (Laravel session id) — never falls through to an identity-less key.
+- `setPrinter()` writes one row per identity present on the request, so the preference is correctly retrievable however many identities apply.
+- `getPrinter()` reads in the order defined by the new `qz-tray.identity_priority` config (default `device → user → session`), returning `config('qz-tray.default_printer')` only when **no** identity has a stored row for that path — never another identity's value.
+- `clearCache()` now deletes the requester's own preference rows (by identity) plus best-effort cleanup of any pre-1.1 Cache/session keys.
+
+---
+
+## BUG-20 — `SmartPrint.print()` never returned the job promise (High / Broken API contract)
+
+**File:** `resources/js/smart-print.js`
+**Function:** public `print()`
+
+**Problem:** The README's "Programmatic API Usage" section documents `const jobId = await SmartPrint.print(url, options);` and `onComplete`/`onError` callbacks in the options object. Neither worked: `enqueue()` returned nothing (undefined), and `print()`'s branches called `enqueue(job)` without a `return`, so the promise chain was severed twice over. `onComplete`/`onError` were never invoked anywhere in the file.
+
+**Fix:**
+- `enqueue()` now creates a `Promise` per job (idempotently — see BUG-22), stores `_resolve`/`_reject` on the job object, and returns it.
+- `print()`, `printRaw()`, `printZPL()`, `printESC()` all `return enqueue(...)`.
+- `printQZ()` settles the promise and invokes `job.onComplete(job)` / `job.onError(err, job)` on every terminal branch (success, each validation failure, unknown type via fallback, and the `qz.print()` catch block).
+- Job IDs are real UUIDs (`crypto.randomUUID()`, with a template-based fallback for older embedded browsers) generated once per job and echoed back in the resolved value: `{ jobId, success }`.
+
+---
+
+## BUG-21 — `GET /qz/jobs` and `DELETE /qz/jobs/{id}` were hardcoded stubs (High)
+
+**File:** `src/Http/Controllers/QzSecurityController.php`
+**Functions:** `jobs()`, `cancelJob()`
+
+**Problem:** `jobs()` always returned `{ jobs: [] }` and `cancelJob($id)` always returned `{ success: true }` regardless of whether `$id` existed — neither queried `qz_print_jobs`. Combined with BUG-05's fix (which did start writing rows) and the fact that `print()` minted its own `uniqid()` server-side that no client code ever received, the queue-management endpoints were structurally unreachable: nothing on the client ever had a job id to cancel, and the "list" endpoint couldn't have shown anything real even if it queried the table.
+
+**Fix:**
+- `print()` now accepts an optional client-supplied `job_id` (validated as `uuid`) and uses it as the row's `uuid` if present, falling back to a server-generated `Str::uuid()` for direct API callers. `smart-print.js` sends its client-generated job id here via a new `logPrintJob()` call after each successful `qz.print()`, so the id returned to `await SmartPrint.print()` is the same id that identifies the row.
+- `jobs()` now queries `qz_print_jobs` for `pending`/`processing` rows, scoped to the requester's `user_id`+`user_type` if authenticated, else their `X-Device-Id` — so one workstation's queue view can't show another's jobs.
+- `cancelJob($id)` looks up the row by `uuid`, returns 404 if not found, 409 if already in a terminal state, and otherwise sets `status = 'cancelled'`.
+- Migration adds `uuid` (unique) and `device_id` columns to `qz_print_jobs`, plus a `(device_id, status)` index for the queue lookup above.
+
+---
+
+## BUG-22 — Printer-selection modal orphaned the original job's promise (High)
+
+**File:** `resources/js/smart-print.js`
+**Function:** `openPrinterModal()`
+
+**Problem:** When `printQZ()` had no printer selected, it opened the modal and returned (with BUG-20's fix, leaving the job's promise pending). The modal's button handler then called `enqueue({ ...jobToQueue, printer })` — spreading the job into a **new object** with a **new** `_resolve`/`_reject` pair once BUG-20 introduced them. Any caller doing `await SmartPrint.print(...)` before a printer had ever been chosen would hang forever: the promise it was holding was never the one that actually got printed.
+
+**Fix:** `enqueue()` is idempotent on an object that already carries a `_promise` — it just re-pushes the same job onto the queue rather than minting a second promise. The modal now mutates `jobToQueue.printer` and re-enqueues the same reference. Cancelling the modal (button or backdrop click) now rejects the pending promise with `"Print cancelled: no printer selected"` instead of leaving it pending indefinitely.
+
+---
+
+## BUG-23 — Pinned QZ Tray client library was one release behind (Low)
+
+**Files:** `README.md`, `src/Console/Commands/InstallQzTray.php`, `resources/views/smart.blade.php`
+
+**Problem:** All three shipped `<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js">`. Upstream (`qzind/tray`) released `2.2.6` in April 2026: race-condition fix in the websocket connection, better locking/concurrency for hardware I/O (serial/network/files/USB), and a Windows SYSTEM-user install fix.
+
+**Fix:** Bumped the pinned CDN version to `2.2.6` in all three files. No package code changes required — this is a client-library version only, and 2.2.x is API-compatible per QZ Tray's own versioning.
+
+---
+
+## BUG-24 — `tenant_id` hardcoded to bigint-only and never actually populated (Medium)
+
+**Files:** `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php`, `src/Http/Controllers/QzSecurityController.php`, `config/qz-tray.php`, `resources/js/smart-print.js`
+
+**Problem:** `qz_print_jobs.tenant_id` was declared `unsignedBigInteger` — the exact same class of bug BUG-12 fixed for `user_id` (this package is installed across multiple client projects whose "project"/"tenant" table's primary key is bigint in some apps, UUID in others; a bigint-only column would reject or truncate a UUID project id). On top of that, `print()` hardcoded `'tenant_id' => null` — the column was never actually written to by any code path, so even bigint-keyed projects got no tenant scoping.
+
+**Fix:**
+- `tenant_id` column changed from `unsignedBigInteger` to `string` (nullable) — holds either a numeric id or a UUID without a schema change, mirroring the `nullableMorphs` approach already used for `user_id`. Added a `(tenant_id, status)` index for per-project queue queries.
+- `print()` now accepts `tenant_id` (or `project_id`, same column, either name accepted) in the request, validated as either an unsigned integer or a UUID via a new `isBigintOrUuid()` helper, and stores it.
+- New `qz-tray.tenant_id_resolver` config: an optional `callable(Request): string|int|null` for multi-tenant host apps that want every job auto-tagged with the current tenant without passing it at every call site — only used when the request didn't supply `tenant_id`/`project_id` explicitly.
+- `jobs()` (queue listing) additively filters by `tenant_id` when present, on top of the existing user/device scoping from BUG-19/21 — narrows the queue further when a shared device/user identity spans more than one project's data.
+- `smart-print.js`'s `logPrintJob()` forwards `job.tenantId`/`job.projectId` (per-job) or `window.QZ_CONFIG.tenantId`/`.projectId` (page-wide default) as `tenant_id` on the print-log request.
+
+---
+
+---
+
+## BUG-25 — Redundant `id` + `uuid` column pair; no way to choose a uuid primary key (v1.1.1)
+
+**Files:** `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php`, `src/Http/Controllers/QzSecurityController.php`, `config/qz-tray.php`
+
+**Problem:** BUG-21's fix (v1.1.0) added a `uuid` column alongside the existing auto-increment `id`, because `id` was never meant to be client-facing (BUG-21's own comment: *"Never expose the auto-increment id to the client — it leaks row counts and is guessable"*). That's correct for installs that want a sequential PK, but it means every install pays for two indexed id columns even when the sequential `id` is never used for anything, and there was no way to just make the primary key itself a uuid.
+
+**Fix:** `qz-tray.id_type` config (`'uuid'` default | `'bigint'`), read at migration time. `id` is now either `$table->uuid('id')->primary()` or `$table->id()` — never both a bigint and a separate uuid column. `print()`, `jobs()`, and `cancelJob()` all operate on `id` directly; in uuid mode the client-generated job id becomes the row's PK with no translation step, in bigint mode the client's id is accepted but the response substitutes the real auto-increment value once the row exists.
+
+**Note:** this is a breaking schema change relative to v1.1.0's migration (see `RELEASE_NOTES.md` upgrade notes) — not additive like BUG-19 through BUG-24.
+
+---
+
+## Files Changed (v1.1.0 – v1.1.1)
+
+| File | Bugs Fixed |
+|------|-----------|
+| `src/Http/Controllers/QzSecurityController.php` | BUG-19, BUG-21, BUG-24, BUG-25 |
+| `config/qz-tray.php` | BUG-19, BUG-24, BUG-25 |
+| `database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php` | BUG-21, BUG-24, BUG-25 |
+| `database/migrations/2026_07_22_000000_create_qz_printer_preferences_table.php` (new) | BUG-19 |
+| `resources/js/smart-print.js` | BUG-19, BUG-20, BUG-21, BUG-22, BUG-24 |
+| `README.md`, `src/Console/Commands/InstallQzTray.php`, `resources/views/smart.blade.php` | BUG-23 |
+
+## New Migration Required
+
+```bash
+php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider" --tag=qz-migrations --force
+php artisan migrate
+```
+
+## Recommendations (not fixed)
+
+1. **`printer-switcher.js`/`printer-status.js`** still only read `SmartPrint.getCurrentPrinter()`/localStorage — consider surfacing `getDeviceId()` in the status widget for on-screen confirmation of which workstation identity is active (useful for lab-PC troubleshooting).
+2. **`identity_priority` = `device` first** is the right default for shared/kiosk workstations (your Mirth Connect PC → multi-analyzer topology) but wrong for apps where a person's printer choice should follow them between machines — flip the config to `['user', 'device', 'session']` for that case.
+3. **`qz_printer_preferences` rows are never pruned.** `printer_cache_duration` config still exists but nothing acts on it now that Cache TTL isn't the storage mechanism. Consider a scheduled command (`qz:prune-preferences --older-than=90`) if this matters for your retention policy.
+4. **`qz_printer_preferences` is not tenant-scoped.** Only `qz_print_jobs` got a `tenant_id` column in this pass. If two projects sharing one database also need isolated printer *memory* (not just job history), the same `tenant_id` (string, bigint-or-uuid) column should be added to `qz_printer_preferences` and folded into `resolveIdentities()`.
diff --git a/README.md b/README.md
index 858183b..a572a82 100644
--- a/README.md
+++ b/README.md
@@ -172,7 +172,7 @@ Add these two lines to your main Blade layout (e.g. `resources/views/layouts/app
     {{-- Your content here --}}
 
     {{-- Step 1: QZ Tray WebSocket library (CDN) --}}
-    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js"></script>
+    <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.6/qz-tray.min.js"></script>
 
     {{-- Step 2: SmartPrint library (published asset) --}}
     <script src="{{ asset('vendor/qz-tray/js/smart-print.js') }}"></script>
@@ -280,6 +280,20 @@ return [
     'remember_printer_per_page' => true,   // Remember per URL path
     'printer_cache_duration'    => 86400,  // seconds (24 hours)
 
+    // v1.1.0+: which identity wins when a request matches more than one
+    // (device UUID, authenticated user, session)? 'device' first is correct
+    // for shared/kiosk workstations where the physical machine — not who's
+    // logged in — determines the printer. Use ['user', 'device', 'session']
+    // if printer choice should follow a person between machines instead.
+    'identity_priority' => ['device', 'user', 'session'],
+
+    // v1.1.1+: primary key type for qz_print_jobs. Read at migration time —
+    // set BEFORE first `php artisan migrate`. 'uuid' (default): id is a
+    // uuid, safe to hand straight to the client (used as-is by
+    // GET /qz/jobs and DELETE /qz/jobs/{id}). 'bigint': plain
+    // auto-increment id.
+    'id_type' => env('QZ_JOB_ID_TYPE', 'uuid'),
+
     // QZ Tray WebSocket connection
     'websocket' => [
         'host'    => env('QZ_WEBSOCKET_HOST', 'localhost'),
@@ -451,13 +465,35 @@ All routes are prefixed with `/qz` by default (configurable).
 | `POST` | `/qz/printer` | `qz.printer.set` | Remember a printer for a URL path |
 | `GET` | `/qz/printer/{path}` | `qz.printer.get` | Get remembered printer for a URL path |
 
+**Device identity (v1.1.0+):** every request to `/qz/printer`, `/qz/print`, `/qz/jobs`, and `/qz/clear-cache` is scoped by whichever of these identities is present, in the order set by `qz-tray.identity_priority` (default `device → user → session`):
+
+- **`device`** — send an `X-Device-Id: <uuid>` header (or `device_id` body/query param). `SmartPrint.getDeviceId()` returns the UUID `smart-print.js` already generates and persists per browser/workstation — use the same value if you call these endpoints yourself.
+- **`user`** — the authenticated user (`auth()->user()`), when the route runs behind an auth middleware.
+- **`session`** — anonymous fallback, isolated per Laravel session.
+
+A request can match more than one identity at once (e.g. a logged-in user on a device-identified kiosk); `POST /qz/printer` writes a preference row for every identity present, so switching `identity_priority` later doesn't lose data. There is **no** unscoped, identity-less fallback — two different users/workstations can never read each other's stored printer.
+
+```js
+// smart-print.js already does this for you on every fetch; shown here for
+// direct API use (e.g. server-to-server or a custom admin dashboard):
+fetch('/qz/printer', {
+    method: 'POST',
+    headers: {
+        'Content-Type': 'application/json',
+        'X-CSRF-TOKEN': csrfToken,
+        'X-Device-Id':  SmartPrint.getDeviceId(),
+    },
+    body: JSON.stringify({ printer: 'Label Printer', path: '/orders/5' }),
+});
+```
+
 ### Print Jobs
 
 | Method | URL | Name | Description |
 |--------|-----|------|-------------|
 | `POST` | `/qz/print` | `qz.print` | Accept and log a print job |
-| `GET` | `/qz/jobs` | `qz.jobs` | List active print jobs |
-| `DELETE` | `/qz/jobs/{id}` | `qz.jobs.cancel` | Cancel a print job |
+| `GET` | `/qz/jobs` | `qz.jobs` | List **this workstation/user's** active print jobs (scoped, see above) |
+| `DELETE` | `/qz/jobs/{id}` | `qz.jobs.cancel` | Cancel a print job by its UUID |
 
 ### Cache & Setup
 
@@ -644,6 +680,34 @@ SmartPrint.setPrinter('HP LaserJet M404', 'global'); // Remember globally
 
 // Open the printer picker modal
 SmartPrint.showPrinterSwitcher();
+
+// Persistent UUID identifying THIS browser/workstation (v1.1.0+).
+// Generated once via crypto.randomUUID() and kept in localStorage.
+// Sent automatically as X-Device-Id on every /qz/* request; call it
+// directly if you're hitting those endpoints yourself (e.g. from an
+// admin dashboard) or want to display which workstation is active.
+const deviceId = SmartPrint.getDeviceId();
+// Returns: string (uuid)
+```
+
+#### Print Job Promises (v1.1.0+)
+
+`print()`, `printRaw()`, `printZPL()`, and `printESC()` all return a `Promise` that resolves once the job actually reaches the printer (or the browser fallback dialog), and rejects on failure — including if the user cancels the printer-selection modal. Prior releases documented this but it silently resolved to `undefined`; it now behaves as documented.
+
+```javascript
+try {
+    const { jobId, success } = await SmartPrint.print('/invoice.pdf', {
+        printer: 'Office Printer',
+        copies: 2,
+        onComplete: (job) => console.log('Printed', job.id),
+        onError:    (err, job) => console.error('Failed', job.id, err),
+    });
+    console.log('Job', jobId, success ? 'printed' : 'failed');
+} catch (err) {
+    // Rejects when: no printer available and the user cancelled the
+    // picker, QZ Tray was offline, or qz.print() itself threw.
+    console.error('Print failed:', err);
+}
 ```
 
 #### Connection
diff --git a/RELEASE_NOTES.md b/RELEASE_NOTES.md
index c5542ea..3b73e0e 100644
--- a/RELEASE_NOTES.md
+++ b/RELEASE_NOTES.md
@@ -6,6 +6,73 @@ Versioning follows [Semantic Versioning](https://semver.org/).
 
 ---
 
+## [v1.1.1] — 2026-07-22
+
+> Consolidates `qz_print_jobs`' two id columns (`id` bigint + `uuid` string) into one, type controlled by config.
+
+### 💥 Schema Change
+
+- **`qz_print_jobs.id` is now config-driven** via the new `qz-tray.id_type` setting (`'uuid'` default, or `'bigint'`). The separate `uuid` column from v1.1.0 has been removed — there is exactly one id column again, but its *type* is now a config choice instead of hardcoded to `unsignedBigInteger`:
+  - `id_type = 'uuid'` — `id` is a `uuid` primary key. The client-generated job id (`smart-print.js`, `crypto.randomUUID()`) is written straight to `id`, so the id returned to the browser always matches the row, with no separate lookup column.
+  - `id_type = 'bigint'` — `id` is a normal auto-increment integer, same as pre-1.1. `job_id` sent by the client is accepted but not used as the PK; the response's `job_id` becomes the real auto-increment value once the insert completes.
+- Read at migration time (`config('qz-tray.id_type')` inside the migration's `up()`), so set it in `.env`/config **before** running `php artisan migrate` for the first time. Changing it afterward has no retroactive effect — write a follow-up migration if you need to convert an existing install.
+- `GET /qz/jobs` and `DELETE /qz/jobs/{id}` now read/query the `id` column in both modes (previously `uuid`-only).
+
+### ⬆️ Upgrade Notes
+
+If you already ran the v1.1.0 migration (which had both `id` and `uuid` columns), this is a breaking schema change — either:
+```bash
+php artisan migrate:rollback --step=1   # drops qz_print_jobs (v1.1.0's migration)
+php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider" --tag=qz-migrations --force
+php artisan migrate
+```
+or write your own follow-up migration to drop the `uuid` column and convert `id`'s type in place if you have existing job history to preserve.
+
+---
+
+## [v1.1.0] — 2026-07-22
+
+> UUID device identity, multi-workstation printer-memory correctness, and queue management wired end-to-end. See `BUG_REPORT.md` addendum for full detail (BUG-19 to BUG-23).
+
+### 🐛 Bug Fixes
+
+- **CRITICAL** — Fixed server-side printer memory (`/qz/printer`) leaking across users/workstations. It previously fell back to a single identity-less `Cache` key per URL path, so one workstation's printer choice could silently become another's default. Replaced with a `qz_printer_preferences` table scoped to `(identity_type, identity_value, path)`.
+- **HIGH** — Fixed `SmartPrint.print()` (and `printRaw`/`printZPL`/`printESC`) never returning the underlying job promise — `await SmartPrint.print(...)`, documented in the README since v1.0.0, always resolved to `undefined`.
+- **HIGH** — Fixed `job.onComplete`/`job.onError` callbacks (documented in the "Options Object" section) never being invoked anywhere.
+- **HIGH** — Fixed `GET /qz/jobs` and `DELETE /qz/jobs/{id}` being hardcoded stubs that never queried the database, making the print-queue management endpoints unreachable in practice.
+- **HIGH** — Fixed the printer-selection modal orphaning a job's promise by re-enqueueing a cloned object instead of the original — a caller awaiting `SmartPrint.print()` before any printer was chosen would hang forever.
+- **LOW** — Bumped pinned QZ Tray client library from `2.2.5` to `2.2.6` (upstream fixed a websocket race condition and improved hardware I/O locking/concurrency).
+- **MEDIUM** — `qz_print_jobs.tenant_id` was `unsignedBigInteger`-only and hardcoded to `null` — same bigint-vs-uuid problem `user_id` had pre-1.1, and never actually populated by any code path. Now a nullable string column accepting either a bigint id or a UUID, actually settable via `tenant_id`/`project_id` on `POST /qz/print`.
+
+### ✨ New Features
+
+- **Device UUID identity** — `smart-print.js` generates and persists a UUID per browser/workstation (`localStorage`, `crypto.randomUUID()`), sent as `X-Device-Id` on every request. Exposed via `SmartPrint.getDeviceId()`.
+- **`qz-tray.identity_priority` config** — controls whether printer memory resolves by `device`, `user`, or `session` first when more than one applies to a request. Defaults to `device` first (correct for shared lab/kiosk workstations where the physical machine, not the logged-in user, determines the printer).
+- **Server-synced printer memory** — `smart-print.js` now optionally backs up/restores printer selection via the server (opt-out with `window.QZ_CONFIG.serverSync = false`), in addition to `localStorage`.
+- **Real, correlated job IDs** — print jobs use client-generated UUIDs that match the `uuid` column on `qz_print_jobs`, so `jobs()` and `cancelJob()` now operate on real, identity-scoped data instead of stubs.
+- **New migration** `2026_07_22_000000_create_qz_printer_preferences_table.php` — durable, identity-scoped printer memory storage.
+- **`tenant_id`/`project_id` dual bigint/UUID support** — `POST /qz/print` accepts either name for the same column; validated as either an integer id or a UUID, so this package works unmodified whether the host project's tenant/project table is bigint- or UUID-keyed. Optional `qz-tray.tenant_id_resolver` config auto-tags jobs for multi-tenant apps that don't want to pass it at every call site.
+
+### 📦 Compatibility
+
+| | Version |
+|---|---|
+| PHP | 8.1, 8.2, 8.3 |
+| Laravel | 10.x, 11.x, 12.x |
+| QZ Tray | 2.2.6 |
+| ext-openssl | Required |
+
+### ⬆️ Upgrade Notes
+
+```bash
+php artisan vendor:publish --provider="Bitdreamit\QzTray\QzTrayServiceProvider" --tag=qz-migrations --force
+php artisan migrate
+```
+
+No breaking changes to public JS/PHP API surfaces — all new behavior is additive or corrects a previously-broken documented contract.
+
+---
+
 ## [v1.0.0] — 2026-05-19 🎉 Current Stable Release
 
 > **Full rewrite and stabilisation pass.** Every known bug from v0.x has been fixed, the JavaScript library has been completely cleaned up, and full documentation is published.
diff --git a/composer.json b/composer.json
index 762b418..93667ae 100644
--- a/composer.json
+++ b/composer.json
@@ -1,6 +1,6 @@
 {
     "name": "bitdreamit/laravel-qz-tray",
-    "version": "1.0.0",
+    "version": "1.1.1",
     "description": "Laravel QZ Tray is a complete silent printing solution that connects your Laravel application to desktop printers via QZ Tray. It allows you to print directly from the browser without print dialogs, with smart caching, printer memory, and automatic fallback.",
     "type": "library",
     "keywords": [
diff --git a/config/qz-tray.php b/config/qz-tray.php
index 81bce6a..38fdb62 100644
--- a/config/qz-tray.php
+++ b/config/qz-tray.php
@@ -61,6 +61,69 @@ return [
     'remember_printer_per_page'  => true,
     'printer_cache_duration'     => 86400,
 
+    /*
+    |--------------------------------------------------------------------------
+    | Printer Memory Identity Priority
+    |--------------------------------------------------------------------------
+    | When resolving which stored printer to use for a path, the request may
+    | match more than one identity (a logged-in user AND a device UUID AND a
+    | session). This defines which one wins, checked in order:
+    |
+    |   'device'  - the workstation/browser (X-Device-Id header). Use this
+    |               first for shared kiosks/lab PCs where the physical
+    |               machine — not who is logged in — determines the printer
+    |               (e.g. a lab PC always prints to its attached label
+    |               printer regardless of which technician is logged in).
+    |   'user'    - the authenticated user. Put this first for apps where a
+    |               person's printer choice should follow them between
+    |               machines.
+    |   'session' - anonymous fallback, isolated per browser session.
+    |
+    | Every identity present on a request is still written on setPrinter(),
+    | so changing this order later does not lose any previously saved
+    | preference.
+    */
+    'identity_priority' => ['device', 'user', 'session'],
+
+    /*
+    |--------------------------------------------------------------------------
+    | Tenant / Project ID Resolver
+    |--------------------------------------------------------------------------
+    | Optional callable(Request $request): string|int|null. Every print job
+    | is tagged with a tenant_id/project_id (see the qz_print_jobs.tenant_id
+    | column — stored as a string so it works whether the calling project's
+    | "project"/"tenant" table uses a bigint or a uuid primary key). Callers
+    | can always pass `tenant_id` or `project_id` explicitly in the request;
+    | this resolver only fires when neither was sent, e.g. for multi-tenant
+    | apps (stancl/tenancy, etc.) that want every job auto-tagged with the
+    | current tenant without passing it at every call site:
+    |
+    |   'tenant_id_resolver' => fn ($request) => tenant('id'),
+    |
+    | Leave null to require explicit tenant_id/project_id on each request.
+    */
+    'tenant_id_resolver' => null,
+
+    /*
+    |--------------------------------------------------------------------------
+    | Print Job ID Type
+    |--------------------------------------------------------------------------
+    | v1.1.1+: controls the primary key type of the qz_print_jobs table.
+    | Read at migration time — change it BEFORE running
+    | `php artisan migrate` for the first time; changing it afterwards has
+    | no effect on an already-created table (drop/recreate or write a new
+    | migration if you need to convert an existing install).
+    |
+    |   'uuid'   (default) - id is a uuid. Never a guessable sequential
+    |             integer, so it's safe to hand straight back to the client
+    |             and is what GET /qz/jobs and DELETE /qz/jobs/{id} use.
+    |   'bigint' - id is a normal auto-increment integer. Slightly smaller/
+    |             faster index; fine for fully internal/admin-only queues
+    |             where exposing a sequential id to the browser isn't a
+    |             concern.
+    */
+    'id_type' => env('QZ_JOB_ID_TYPE', 'uuid'),
+
     /*
     |--------------------------------------------------------------------------
     | WebSocket Settings
diff --git a/database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php b/database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php
index 03f795c..3d1feb9 100644
--- a/database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php
+++ b/database/migrations/2026_01_01_000000_create_qz_print_jobs_table.php
@@ -8,23 +8,65 @@ return new class extends Migration
 {
     public function up(): void
     {
-        Schema::create('qz_print_jobs', function (Blueprint $table) {
-            $table->id();
-            $table->unsignedBigInteger('tenant_id')->nullable();
+        // v1.1.1: the primary key itself is now either a uuid or a bigint,
+        // controlled by config('qz-tray.id_type') — no more separate `id`
+        // (bigint, internal) + `uuid` (string, public-facing) pair. Reading
+        // config() during a migration is safe; the app is fully booted by
+        // the time migrations run.
+        //
+        //   'uuid'   (default) — $table->uuid('id')->primary(). The id is
+        //             never a guessable sequential integer, so it's safe to
+        //             hand straight back to the client and used as-is by
+        //             GET /qz/jobs and DELETE /qz/jobs/{id}.
+        //   'bigint' — $table->id() (auto-increment). Slightly smaller/
+        //             faster index for installs that don't care about
+        //             exposing sequential ids (e.g. fully internal/admin-
+        //             only queue) or that want to stay consistent with an
+        //             existing bigint-only schema convention.
+        $usesUuid = config('qz-tray.id_type', 'uuid') === 'uuid';
+
+        Schema::create('qz_print_jobs', function (Blueprint $table) use ($usesUuid) {
+            if ($usesUuid) {
+                $table->uuid('id')->primary();
+            } else {
+                $table->id();
+            }
+            // Project/tenant identifier. This package is reused across
+            // multiple client projects (bitdreamit.com) whose "project" or
+            // "tenant" table's primary key is bigint in some apps and uuid
+            // in others. A plain unsignedBigInteger here would silently
+            // truncate/reject a uuid value from any uuid-keyed project —
+            // same class of bug user_id had before nullableMorphs. Stored
+            // as a string so either "482" or
+            // "b2b1f6c0-3b3d-4c9a-9e2e-1a2b3c4d5e6f" fits without a schema
+            // change; validate the incoming value's *shape* at the
+            // controller layer instead of constraining it at the DB layer.
+            $table->string('tenant_id')->nullable();
             // Use a nullable string for user_id so the package works with both
             // integer-keyed (default) and UUID/ULID-keyed user models without
             // breaking the FK constraint. We index it for fast lookups.
             $table->nullableMorphs('user');
+            // Identifies the physical workstation/browser that submitted the
+            // job, independent of the logged-in user. Populated from the
+            // `X-Device-Id` header sent by smart-print.js (a UUID persisted
+            // in that browser's localStorage). This is what makes it possible
+            // to tell apart two different PCs printing through the same
+            // shared user session (e.g. a kiosk/lab-analyzer login), and is
+            // required for correct multi-workstation printer-memory scoping.
+            $table->uuid('device_id')->nullable();
             $table->string('printer_name');
             $table->string('document_url')->nullable();
             $table->string('document_type')->default('pdf');
             $table->integer('copies')->default(1);
-            $table->string('status')->default('pending'); // pending, processing, completed, failed
+            $table->string('status')->default('pending'); // pending, processing, completed, failed, cancelled
+            $table->text('error_message')->nullable();
             $table->json('metadata')->nullable();
             $table->timestamp('processed_at')->nullable();
             $table->timestamps();
 
             $table->index(['user_id', 'user_type', 'status']);
+            $table->index(['device_id', 'status']);
+            $table->index(['tenant_id', 'status']);
             $table->index(['status', 'created_at']);
             $table->index('processed_at');
         });
diff --git a/database/migrations/2026_07_22_000000_create_qz_printer_preferences_table.php b/database/migrations/2026_07_22_000000_create_qz_printer_preferences_table.php
new file mode 100644
index 0000000..74be40b
--- /dev/null
+++ b/database/migrations/2026_07_22_000000_create_qz_printer_preferences_table.php
@@ -0,0 +1,48 @@
+<?php
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * Durable, correctly-scoped replacement for the Cache-only printer memory
+ * used pre-1.1. The old implementation kept a single global Cache key per
+ * `path` (`qz.printer.{path}`) as a fallback whenever a request had no
+ * session value yet — meaning workstation/user A's printer choice could
+ * silently become workstation/user B's default the next time B opened the
+ * same page and B's own session/device had not yet set a preference.
+ *
+ * This table makes every stored preference explicitly scoped to exactly one
+ * identity so two different identities can never read each other's printer:
+ *
+ *   identity_type = 'user'    -> identity_value = auth()->id() (string form)
+ *   identity_type = 'device'  -> identity_value = client UUID (X-Device-Id)
+ *   identity_type = 'session' -> identity_value = session()->getId()
+ *
+ * Both an authenticated user AND an anonymous device/session can be stored
+ * side by side ("both support") — QzSecurityController prefers user, then
+ * device, then session, and never mixes one identity's row into another's
+ * response.
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('qz_printer_preferences', function (Blueprint $table) {
+            $table->id();
+            $table->string('identity_type', 20); // user | device | session
+            $table->string('identity_value');    // user id, device UUID, or session id
+            $table->string('path', 500);
+            $table->string('printer_name');
+            $table->timestamps();
+
+            $table->unique(['identity_type', 'identity_value', 'path'], 'qz_pref_identity_path_unique');
+            $table->index(['identity_type', 'identity_value']);
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('qz_printer_preferences');
+    }
+};
diff --git a/resources/js/smart-print.js b/resources/js/smart-print.js
index f824f2e..3ebf43b 100644
--- a/resources/js/smart-print.js
+++ b/resources/js/smart-print.js
@@ -10,8 +10,58 @@
 window.SmartPrint = (() => {
     const STORAGE_PREFIX = 'smart_printer:';
     const GLOBAL_KEY     = 'smart_printer_global';
+    const DEVICE_ID_KEY  = 'smart_print_device_id';
     let processingQueue  = false; // prevent concurrent processQueue calls
 
+    // ============================
+    // UUID helpers
+    // ============================
+    // Prefer crypto.randomUUID (all modern browsers). Fall back to a
+    // template-based uuid4 generator for older WebViews / embedded Trident
+    // browsers sometimes used on lab/kiosk workstations that don't expose it.
+    function uuid4() {
+        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
+            return crypto.randomUUID();
+        }
+        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
+            const r = (Math.random() * 16) | 0;
+            const v = c === 'x' ? r : (r & 0x3) | 0x8;
+            return v.toString(16);
+        });
+    }
+
+    // Persistent per-browser identifier for THIS workstation. Distinct from
+    // job ids: it never changes once generated, so the server can tell two
+    // different physical machines apart even when they share a Laravel
+    // session/login (e.g. multiple lab PCs logged in as the same clinic
+    // account). Used to scope server-side printer memory and print-job
+    // logging so one workstation's settings/queue never leak into another's.
+    function getDeviceId() {
+        try {
+            let id = localStorage.getItem(DEVICE_ID_KEY);
+            if (!id) {
+                id = uuid4();
+                localStorage.setItem(DEVICE_ID_KEY, id);
+            }
+            return id;
+        } catch (e) {
+            // localStorage unavailable (private browsing) — fall back to an
+            // in-memory id that's at least stable for this page session.
+            return state._volatileDeviceId || (state._volatileDeviceId = uuid4());
+        }
+    }
+
+    function csrfToken() {
+        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
+    }
+
+    // Server sync is opt-out via window.QZ_CONFIG.serverSync = false, for
+    // deployments that only ever want the localStorage-only behavior of
+    // pre-1.1 releases.
+    function serverSyncEnabled() {
+        return !(window.QZ_CONFIG && window.QZ_CONFIG.serverSync === false);
+    }
+
     const state = {
         qzReady:      false,
         connecting:   false,
@@ -44,7 +94,10 @@ window.SmartPrint = (() => {
         if (!window.qz) return;
 
         qz.security.setCertificatePromise(resolve =>
-            fetch('/qz/certificate', { cache: 'no-store' })
+            fetch('/qz/certificate', {
+                cache: 'no-store',
+                headers: { 'X-Device-Id': getDeviceId() },
+            })
                 .then(r => {
                     if (!r.ok) throw new Error('Certificate fetch failed: ' + r.status);
                     return r.text();
@@ -60,7 +113,8 @@ window.SmartPrint = (() => {
                 cache:  'no-store',
                 headers: {
                     'Content-Type':  'application/json',
-                    'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
+                    'X-CSRF-TOKEN':  csrfToken(),
+                    'X-Device-Id':   getDeviceId(),
                     'Accept':        'text/plain',
                 },
                 body: JSON.stringify({ data: toSign }),
@@ -139,16 +193,45 @@ window.SmartPrint = (() => {
     // Printer Memory
     // ============================
     function restorePrinter() {
+        let saved = null;
         try {
-            const saved = localStorage.getItem(STORAGE_PREFIX + pathKey())
-                       || localStorage.getItem(GLOBAL_KEY);
+            saved = localStorage.getItem(STORAGE_PREFIX + pathKey())
+                 || localStorage.getItem(GLOBAL_KEY);
             // Only restore if printer is in the current list (or list is empty = first connect)
             if (saved && (state.printers.length === 0 || state.printers.includes(saved))) {
                 state.currentPrinter = saved;
+            } else {
+                saved = null;
             }
         } catch (e) {
             // localStorage may be unavailable (private browsing, etc.)
         }
+
+        // localStorage is per-browser, so it already isolates two different
+        // workstations from each other. The server round-trip below exists
+        // for the OTHER case: this same workstation's browser profile was
+        // reset/cleared, or a fresh browser is opened on the same physical
+        // device — server memory (scoped by the device UUID, which is
+        // regenerated only if localStorage itself is cleared) lets it pick
+        // its printer back up without asking again. It never overrides a
+        // value localStorage already had.
+        if (!saved && serverSyncEnabled()) {
+            fetch('/qz/printer/' + encodeURIComponent(pathKey()), {
+                headers: { 'X-Device-Id': getDeviceId() },
+                cache: 'no-store',
+            })
+                .then(r => r.ok ? r.json() : null)
+                .then(json => {
+                    const printer = json && json.printer;
+                    if (printer && !state.currentPrinter
+                        && (state.printers.length === 0 || state.printers.includes(printer))) {
+                        state.currentPrinter = printer;
+                        try { localStorage.setItem(STORAGE_PREFIX + pathKey(), printer); } catch (e) {}
+                        emit('printer-restored', { printer, source: json.scoped_to || 'default' });
+                    }
+                })
+                .catch(() => {}); // best-effort; localStorage/modal remain the source of truth
+        }
     }
 
     function rememberPrinter(printer, scope) {
@@ -163,6 +246,20 @@ window.SmartPrint = (() => {
             state.channel.postMessage({ printer });
         }
 
+        if (serverSyncEnabled()) {
+            fetch('/qz/printer', {
+                method: 'POST',
+                cache:  'no-store',
+                headers: {
+                    'Content-Type': 'application/json',
+                    'X-CSRF-TOKEN': csrfToken(),
+                    'X-Device-Id':  getDeviceId(),
+                    'Accept':       'application/json',
+                },
+                body: JSON.stringify({ printer, path: pathKey(), device_id: getDeviceId() }),
+            }).catch(() => {}); // fire-and-forget; localStorage already has the authoritative copy
+        }
+
         emit('printer-saved', { printer, scope });
     }
 
@@ -177,11 +274,28 @@ window.SmartPrint = (() => {
     // ============================
     // Queue Management
     // ============================
+    // Idempotent: if `job` already carries a pending `_promise` (e.g. it is
+    // being re-submitted after the printer-selection modal was answered),
+    // that same promise is reused instead of creating a second, orphaned
+    // one — otherwise a caller doing `await SmartPrint.print(...)` before
+    // any printer had been chosen would hang forever, because the original
+    // promise would never be the one actually printed.
     function enqueue(job) {
+        if (!job._promise) {
+            job.id = job.id || uuid4();
+            job._promise = new Promise((resolve, reject) => {
+                job._resolve = resolve;
+                job._reject  = reject;
+            });
+            // Don't let an unawaited enqueue() (the common DOM-click path)
+            // produce an "Uncaught (in promise)" console error.
+            job._promise.catch(() => {});
+        }
         state.queue.push(job);
         updateQueueUI();
         emit('job-queued', { job });
         processQueue();
+        return job._promise;
     }
 
     async function processQueue() {
@@ -211,11 +325,18 @@ window.SmartPrint = (() => {
     function offlineBuffer(job) {
         state.failedQueue.push(job);
         try {
+            // Strip function/promise fields — JSON.stringify silently drops
+            // functions anyway, but being explicit avoids surprises if a
+            // future job field holds a class instance with a toJSON trap.
+            const { _resolve, _reject, _promise, ...serializable } = job;
             const offline = JSON.parse(localStorage.getItem('sp_offline_queue') || '[]');
-            offline.push(job);
+            offline.push(serializable);
             localStorage.setItem('sp_offline_queue', JSON.stringify(offline));
         } catch (e) {}
         emit('job-failed', { job });
+        // The original caller (if any) gets a clear rejection now rather
+        // than hanging until an eventual retry succeeds minutes/hours later.
+        job._reject && job._reject(new Error('QZ Tray offline; job stored for retry'));
         console.warn('[SmartPrint] QZ Tray offline – job stored for retry.');
     }
 
@@ -229,6 +350,53 @@ window.SmartPrint = (() => {
         } catch (e) {}
     }
 
+    // ============================
+    // Server-side job logging (best-effort, non-blocking)
+    // ============================
+    // Sends the SAME client-generated job.id as `job_id` so the row created
+    // here is the one GET /qz/jobs and DELETE /qz/jobs/{id} can look up —
+    // previously the server minted its own uniqid() that no client code
+    // ever saw, so the queue/cancel endpoints were unreachable from the UI.
+    function logPrintJob(job, printer, status) {
+        if (!serverSyncEnabled()) return;
+        // Per-job value wins; otherwise fall back to a page-wide default set
+        // by the host app (e.g. window.QZ_CONFIG.tenantId = '{{ $project->id }}'
+        // — works whether that id is a bigint or a uuid string).
+        const tenantId = job.tenantId ?? job.projectId
+            ?? (window.QZ_CONFIG && (window.QZ_CONFIG.tenantId ?? window.QZ_CONFIG.projectId))
+            ?? undefined;
+
+        fetch('/qz/print', {
+            method: 'POST',
+            cache:  'no-store',
+            headers: {
+                'Content-Type': 'application/json',
+                'X-CSRF-TOKEN': csrfToken(),
+                'X-Device-Id':  getDeviceId(),
+                'Accept':       'application/json',
+            },
+            body: JSON.stringify({
+                job_id:    job.id,
+                printer,
+                type:      job.type,
+                url:       job.url || undefined,
+                data:      job.url ? undefined : (job.data || undefined),
+                copies:    job.copies,
+                device_id: getDeviceId(),
+                tenant_id: tenantId !== undefined ? String(tenantId) : undefined,
+                metadata:  { status: status || 'completed' },
+            }),
+        }).catch(() => {}); // logging failure must never block/alter the print result
+    }
+
+    // Invokes job.onComplete/job.onError if the caller supplied one via the
+    // options object (documented in the README's "Options Object" section,
+    // but never actually called anywhere before 1.1).
+    function safeCallback(fn, ...args) {
+        if (typeof fn !== 'function') return;
+        try { fn(...args); } catch (e) { console.error('[SmartPrint] job callback error', e); }
+    }
+
     // ============================
     // Core print function
     // ============================
@@ -236,6 +404,8 @@ window.SmartPrint = (() => {
         const printer = job.printer || state.currentPrinter;
 
         if (!printer) {
+            // Promise stays pending — resolved/rejected once the user
+            // answers the printer-selection modal (see openPrinterModal).
             openPrinterModal(job);
             return;
         }
@@ -264,16 +434,22 @@ window.SmartPrint = (() => {
         switch (job.type) {
             case 'pdf':
                 if (!job.url) {
+                    const err = new Error('Missing PDF url');
                     console.error('[SmartPrint] PDF print requires a url.');
-                    emit('job-failed', { job, error: new Error('Missing PDF url') });
+                    emit('job-failed', { job, error: err });
+                    safeCallback(job.onError, err, job);
+                    job._reject && job._reject(err);
                     return;
                 }
                 payload = [{ type: 'pdf', data: job.url }];
                 break;
             case 'html':
                 if (!job.data && !job.url) {
+                    const err = new Error('Missing HTML data');
                     console.error('[SmartPrint] HTML print requires data or url.');
-                    emit('job-failed', { job, error: new Error('Missing HTML data') });
+                    emit('job-failed', { job, error: err });
+                    safeCallback(job.onError, err, job);
+                    job._reject && job._reject(err);
                     return;
                 }
                 payload = [{ type: 'html', data: job.data || job.url }];
@@ -282,24 +458,38 @@ window.SmartPrint = (() => {
             case 'raw':
             case 'escpos':
                 if (!job.data) {
+                    const err = new Error('Missing raw data for ' + job.type + ' print');
                     console.error('[SmartPrint] ' + job.type + ' print requires data.');
-                    emit('job-failed', { job, error: new Error('Missing raw data') });
+                    emit('job-failed', { job, error: err });
+                    safeCallback(job.onError, err, job);
+                    job._reject && job._reject(err);
                     return;
                 }
                 payload = [{ type: 'raw', format: 'command', data: job.data }];
                 break;
             default:
+                // Unrecognised type: the browser print dialog is the best
+                // we can do, so treat it as a (non-silent) success rather
+                // than leaving the promise unsettled.
                 fallback(job);
+                emit('job-completed', { job, fallback: true });
+                safeCallback(job.onComplete, job);
+                job._resolve && job._resolve({ jobId: job.id, success: true, fallback: true });
                 return;
         }
 
         try {
             await qz.print(cfg, payload);
             emit('job-completed', { job });
+            logPrintJob(job, printer, 'completed');
+            safeCallback(job.onComplete, job);
+            job._resolve && job._resolve({ jobId: job.id, success: true });
         } catch (err) {
             console.error('[SmartPrint] Print error:', err);
             emit('job-failed', { job, error: err });
+            safeCallback(job.onError, err, job);
             fallback(job);
+            job._reject && job._reject(err);
         }
     }
 
@@ -368,15 +558,28 @@ window.SmartPrint = (() => {
                 rememberPrinter(printer);
                 modal.remove();
                 if (jobToQueue && (jobToQueue.url || jobToQueue.data)) {
-                    enqueue({ ...jobToQueue, printer });
+                    // Mutate + re-enqueue the SAME job object rather than
+                    // spreading it into a new one. enqueue() is idempotent
+                    // on an object that already has `_promise`, so this
+                    // resolves/rejects the original promise a caller may be
+                    // awaiting instead of orphaning it behind a clone.
+                    jobToQueue.printer = printer;
+                    enqueue(jobToQueue);
                 }
             };
         });
 
-        modal.querySelector('#sp-modal-cancel').onclick = () => modal.remove();
+        const abandon = () => {
+            modal.remove();
+            if (jobToQueue && jobToQueue._reject) {
+                jobToQueue._reject(new Error('Print cancelled: no printer selected'));
+            }
+        };
+
+        modal.querySelector('#sp-modal-cancel').onclick = abandon;
 
         // Close on backdrop click
-        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
+        modal.addEventListener('click', e => { if (e.target === modal) abandon(); });
     }
 
     // ============================
@@ -511,13 +714,12 @@ window.SmartPrint = (() => {
         init,
         print: (urlOrOptions, options) => {
             if (typeof urlOrOptions === 'string') {
-                enqueue({ url: urlOrOptions, type: 'pdf', copies: 1, ...options });
-            } else {
-                // Normalise copies to an integer so downstream code can rely on it.
-                const job = { ...urlOrOptions };
-                if (job.copies !== undefined) job.copies = parseInt(job.copies, 10) || 1;
-                enqueue(job);
+                return enqueue({ url: urlOrOptions, type: 'pdf', copies: 1, ...options });
             }
+            // Normalise copies to an integer so downstream code can rely on it.
+            const job = { ...urlOrOptions };
+            if (job.copies !== undefined) job.copies = parseInt(job.copies, 10) || 1;
+            return enqueue(job);
         },
         printRaw: (data, type, printer) => enqueue({ data, type: type || 'raw', printer, copies: 1 }),
         printZPL: (zpl, printer)   => enqueue({ data: zpl,   type: 'zpl',    printer, copies: 1 }),
@@ -529,6 +731,9 @@ window.SmartPrint = (() => {
         getCurrentPrinter:   () => state.currentPrinter,
         showPrinterSwitcher: () => openPrinterModal(null),
 
+        // Device identity (UUID persisted per-browser/workstation)
+        getDeviceId,
+
         // Connection
         connect:     connectQZ,
         disconnect:  () => window.qz ? qz.websocket.disconnect() : Promise.resolve(),
diff --git a/resources/views/smart.blade.php b/resources/views/smart.blade.php
index bf540cc..2632bec 100644
--- a/resources/views/smart.blade.php
+++ b/resources/views/smart.blade.php
@@ -199,7 +199,7 @@
 </div>
 
 {{-- QZ Tray library (CDN) --}}
-<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js"></script>
+<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.6/qz-tray.min.js"></script>
 {{-- Laravel QZ Tray smart-print library --}}
 <script src="{{ asset('vendor/qz-tray/js/smart-print.js') }}"></script>
 
diff --git a/src/Console/Commands/InstallQzTray.php b/src/Console/Commands/InstallQzTray.php
index 47434e8..64c69a7 100644
--- a/src/Console/Commands/InstallQzTray.php
+++ b/src/Console/Commands/InstallQzTray.php
@@ -44,7 +44,7 @@ class InstallQzTray extends Command
         $this->line('  1. Download & install QZ Tray on client machines: https://qz.io/download');
         $this->line('  2. Run migrations: php artisan migrate');
         $this->line('  3. Add to your layout:');
-        $this->line('       <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js"></script>');
+        $this->line('       <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.6/qz-tray.min.js"></script>');
         $this->line('       <script src="{{ asset(\'vendor/qz-tray/js/smart-print.js\') }}"></script>');
         $this->line('  4. Visit: /qz/status  to verify your setup');
         $this->newLine();
diff --git a/src/Http/Controllers/QzSecurityController.php b/src/Http/Controllers/QzSecurityController.php
index 318a388..cf0b47c 100644
--- a/src/Http/Controllers/QzSecurityController.php
+++ b/src/Http/Controllers/QzSecurityController.php
@@ -110,68 +110,155 @@ class QzSecurityController extends Controller
         ]);
     }
 
+    /**
+     * Resolve every identity applicable to the current request, in the
+     * order defined by `qz-tray.identity_priority` (default: device, user,
+     * session). A request can legitimately match more than one identity —
+     * e.g. a logged-in user on a lab workstation that also sends a device
+     * UUID — in which case `setPrinter` writes a row for every identity
+     * present (so it stays correct however priority is configured), and
+     * `getPrinter` reads the first configured priority that has a stored
+     * row.
+     *
+     * IMPORTANT: unlike the old implementation, there is no global,
+     * identity-less fallback key. A path with no matching identity row
+     * always falls through to `config('qz-tray.default_printer')`, never
+     * to some other user's/device's last selection.
+     */
+    /**
+     * True if $value is either an unsigned integer (bigint-keyed project
+     * table) or a UUID (uuid-keyed project table). Used to validate
+     * tenant_id/project_id without hardcoding a single PK type — this
+     * package is installed across multiple client projects that don't all
+     * key their "project"/"tenant" table the same way.
+     */
+    private function isBigintOrUuid(?string $value): bool
+    {
+        if ($value === null || $value === '') {
+            return true; // nullable — handled by the 'nullable' rule, not here
+        }
+
+        return (bool) preg_match('/^\d+$/', $value)
+            || (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
+    }
+
+    private function resolveIdentities(Request $request): array
+    {
+        $identities = [];
+
+        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
+        if ($deviceId && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
+            $identities['device'] = $deviceId;
+        }
+
+        $user = $request->user();
+        if ($user) {
+            $identities['user'] = (string) $user->getAuthIdentifier();
+        }
+
+        // Session is always available under the `web` middleware and acts
+        // as the final, still-isolated fallback for anonymous requests that
+        // didn't send a device UUID (e.g. an older client build).
+        if ($request->hasSession()) {
+            $identities['session'] = $request->session()->getId();
+        }
+
+        return $identities;
+    }
+
     public function setPrinter(Request $request): \Illuminate\Http\JsonResponse
     {
         $validated = $request->validate([
-            'printer' => 'required|string|max:255',
-            'path'    => 'required|string|max:500',
+            'printer'   => 'required|string|max:255',
+            'path'      => 'required|string|max:500',
+            'device_id' => 'nullable|uuid',
         ]);
 
-        $safePath = preg_replace('/[^a-zA-Z0-9\-_\/]/', '_', $validated['path']);
-        $key = 'qz.printer.' . $safePath;
-        $ttl = config('qz-tray.printer_cache_duration', 86400);
+        $identities = $this->resolveIdentities($request);
 
-        Cache::put($key, $validated['printer'], $ttl);
-
-        // Track keys so qz:clear-cache can find all of them
-        $keys = Cache::get('qz.printer_keys', []);
-        if (! in_array($key, $keys)) {
-            $keys[] = $key;
-            Cache::put('qz.printer_keys', $keys, $ttl);
+        if (empty($identities)) {
+            return response()->json([
+                'success' => false,
+                'message' => 'No identity (user, device, or session) available to scope this preference to.',
+            ], 422);
         }
 
-        session()->put($key, $validated['printer']);
+        foreach ($identities as $type => $value) {
+            \DB::table('qz_printer_preferences')->updateOrInsert(
+                ['identity_type' => $type, 'identity_value' => $value, 'path' => $validated['path']],
+                ['printer_name' => $validated['printer'], 'updated_at' => now(), 'created_at' => now()]
+            );
+        }
 
         return response()->json([
-            'success' => true,
-            'printer' => $validated['printer'],
-            'path'    => $validated['path'],
+            'success'    => true,
+            'printer'    => $validated['printer'],
+            'path'       => $validated['path'],
+            'scoped_to'  => array_keys($identities),
         ]);
     }
 
-    public function getPrinter(string $path): \Illuminate\Http\JsonResponse
+    public function getPrinter(Request $request, string $path): \Illuminate\Http\JsonResponse
     {
-        $safePath = preg_replace('/[^a-zA-Z0-9\-_\/]/', '_', $path);
-        $key = 'qz.printer.' . $safePath;
+        $identities = $this->resolveIdentities($request);
+        $priority   = config('qz-tray.identity_priority', ['device', 'user', 'session']);
 
-        $printer = session()->get($key)
-            ?? Cache::get($key)
-            ?? config('qz-tray.default_printer');
+        $printer = null;
+        $matchedType = null;
+
+        foreach ($priority as $type) {
+            if (! isset($identities[$type])) {
+                continue;
+            }
+
+            $row = \DB::table('qz_printer_preferences')
+                ->where('identity_type', $type)
+                ->where('identity_value', $identities[$type])
+                ->where('path', $path)
+                ->first();
+
+            if ($row) {
+                $printer     = $row->printer_name;
+                $matchedType = $type;
+                break;
+            }
+        }
 
         return response()->json([
-            'success' => true,
-            'printer' => $printer,
-            'path'    => $path,
+            'success'    => true,
+            'printer'    => $printer ?? config('qz-tray.default_printer'),
+            'path'       => $path,
+            'scoped_to'  => $matchedType, // null when falling back to the global default
         ]);
     }
 
-    public function clearCache(): \Illuminate\Http\JsonResponse
+    public function clearCache(Request $request): \Illuminate\Http\JsonResponse
     {
+        $identities = $this->resolveIdentities($request);
+
+        $deleted = 0;
+        foreach ($identities as $type => $value) {
+            $deleted += \DB::table('qz_printer_preferences')
+                ->where('identity_type', $type)
+                ->where('identity_value', $value)
+                ->delete();
+        }
+
+        // Legacy Cache/session keys from pre-1.1 installs, cleaned up best-effort.
         foreach (session()->all() as $key => $value) {
             if (str_starts_with($key, 'qz.printer.')) {
                 session()->forget($key);
             }
         }
-
-        $keys = Cache::get('qz.printer_keys', []);
-        foreach ($keys as $key) {
+        $legacyKeys = Cache::get('qz.printer_keys', []);
+        foreach ($legacyKeys as $key) {
             Cache::forget($key);
         }
         Cache::forget('qz.printer_keys');
 
         return response()->json([
             'success'   => true,
-            'message'   => 'Printer cache cleared',
+            'message'   => "Printer cache cleared ({$deleted} preference rows removed)",
             'timestamp' => now()->toIso8601String(),
         ]);
     }
@@ -194,11 +281,59 @@ class QzSecurityController extends Controller
             'url'        => 'required_without:data|nullable|string|max:2048',
             'copies'     => 'nullable|integer|min:1|max:999',
             'document'   => 'nullable|string|max:255',
+            'device_id'  => 'nullable|uuid',
+            'job_id'     => 'nullable|uuid',
             'metadata'   => 'nullable|array',
+            // Accepted under either name: some host apps call it
+            // "tenant_id", others "project_id" — same value, one column.
+            'tenant_id'  => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
+                if (! $this->isBigintOrUuid($value)) {
+                    $fail("The {$attribute} must be either an integer id or a UUID.");
+                }
+            }],
+            'project_id' => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
+                if (! $this->isBigintOrUuid($value)) {
+                    $fail("The {$attribute} must be either an integer id or a UUID.");
+                }
+            }],
         ]);
 
-        $jobId = uniqid('qz_', true);
+        // v1.1.1: the primary key IS the job identifier now (no separate
+        // `id` (bigint) + `uuid` (string) pair — see the migration). Which
+        // type it is was fixed at migrate-time by config('qz-tray.id_type'):
+        //
+        //   uuid mode   — the client-generated id (smart-print.js mints one
+        //                 per job via crypto.randomUUID() and sends it as
+        //                 `job_id`) IS what gets written to `id`, so the id
+        //                 returned to the browser always matches the row —
+        //                 no lookup/translation step needed.
+        //   bigint mode — a client-supplied job_id can't become the PK, so
+        //                 the row is inserted without one and the
+        //                 database-assigned auto-increment value becomes
+        //                 $jobId instead, once the insert below completes.
+        $usesUuid   = config('qz-tray.id_type', 'uuid') === 'uuid';
+        $clientJobId = $request->input('job_id');
+        $jobId = ($usesUuid && $clientJobId)
+            ? $clientJobId
+            // Not collision-safe uniqid() (used pre-1.1) — Str::uuid()
+            // (uuid4, via ramsey/uuid, already a Laravel dependency) is.
+            // Also serves as the pre-insert placeholder in bigint mode,
+            // for the (db_logged === false) response path below.
+            : (string) \Illuminate\Support\Str::uuid();
         $type  = $request->input('type');
+        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
+
+        // Project/tenant id: explicit request value wins (bigint OR uuid,
+        // whichever the host app's project model uses — see the migration
+        // comment on the `tenant_id` column). If the host app didn't send
+        // one, fall back to an optional app-supplied resolver — useful for
+        // multi-tenant apps (e.g. stancl/tenancy) that want every print job
+        // auto-tagged with the current tenant without every call site
+        // having to pass it explicitly.
+        $tenantId = $request->input('tenant_id') ?? $request->input('project_id');
+        if ($tenantId === null && is_callable(config('qz-tray.tenant_id_resolver'))) {
+            $tenantId = call_user_func(config('qz-tray.tenant_id_resolver'), $request);
+        }
 
         // Persist to database when the qz_print_jobs table exists.
         // This makes the migration that ships with the package actually useful.
@@ -206,10 +341,11 @@ class QzSecurityController extends Controller
         if (\Illuminate\Support\Facades\Schema::hasTable('qz_print_jobs')) {
             try {
                 $user = $request->user();
-                \DB::table('qz_print_jobs')->insert([
-                    'tenant_id'     => null,
+                $row = [
+                    'tenant_id'     => $tenantId,
                     'user_id'       => $user?->getAuthIdentifier(),
                     'user_type'     => $user ? get_class($user) : null,
+                    'device_id'     => $deviceId,
                     'printer_name'  => $request->input('printer'),
                     'document_url'  => $request->input('url', ''),
                     'document_type' => $type,
@@ -218,7 +354,18 @@ class QzSecurityController extends Controller
                     'metadata'      => json_encode($request->input('metadata', [])),
                     'created_at'    => now(),
                     'updated_at'    => now(),
-                ]);
+                ];
+
+                if ($usesUuid) {
+                    $row['id'] = $jobId;
+                    \DB::table('qz_print_jobs')->insert($row);
+                } else {
+                    // Auto-increment PK: the id can only be known after
+                    // insert. Overwrites the placeholder uuid above with
+                    // the real row id so the response's job_id actually
+                    // matches what jobs()/cancelJob() can look up.
+                    $jobId = (string) \DB::table('qz_print_jobs')->insertGetId($row);
+                }
                 $dbLogged = true;
             } catch (\Throwable $e) {
                 Log::warning('[QZ Tray] Could not persist print job to DB: ' . $e->getMessage());
@@ -246,17 +393,69 @@ class QzSecurityController extends Controller
         ]);
     }
 
-    public function jobs(): \Illuminate\Http\JsonResponse
+    public function jobs(Request $request): \Illuminate\Http\JsonResponse
     {
-        return response()->json([
-            'success' => true,
-            'jobs'    => [],
-            'message' => 'No active print jobs',
-        ]);
+        if (! \Illuminate\Support\Facades\Schema::hasTable('qz_print_jobs')) {
+            return response()->json(['success' => true, 'jobs' => [], 'message' => 'qz_print_jobs table not migrated']);
+        }
+
+        $query = \DB::table('qz_print_jobs')
+            ->whereIn('status', ['pending', 'processing'])
+            ->orderBy('created_at');
+
+        // Scope the queue to the requesting identity so PC-1's queue view
+        // never shows PC-2's jobs (or vice versa) when several workstations
+        // share the same Laravel session/auth guard.
+        $user     = $request->user();
+        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
+        if ($user) {
+            $query->where('user_id', (string) $user->getAuthIdentifier())->where('user_type', get_class($user));
+        } elseif ($deviceId) {
+            $query->where('device_id', $deviceId);
+        }
+
+        // Additive: when a tenant_id/project_id is supplied (explicitly or
+        // via the resolver), narrow further to that project — matters when
+        // a shared device/user identity is reused across more than one
+        // project's data within the same host app.
+        $tenantId = $request->input('tenant_id') ?? $request->input('project_id');
+        if ($tenantId === null && is_callable(config('qz-tray.tenant_id_resolver'))) {
+            $tenantId = call_user_func(config('qz-tray.tenant_id_resolver'), $request);
+        }
+        if ($tenantId !== null && $this->isBigintOrUuid((string) $tenantId)) {
+            $query->where('tenant_id', (string) $tenantId);
+        }
+
+        $jobs = $query->limit(100)->get(['id', 'printer_name', 'document_type', 'status', 'copies', 'created_at']);
+
+        return response()->json(['success' => true, 'jobs' => $jobs]);
     }
 
-    public function cancelJob($id): \Illuminate\Http\JsonResponse
+    public function cancelJob(Request $request, string $id): \Illuminate\Http\JsonResponse
     {
+        if (! \Illuminate\Support\Facades\Schema::hasTable('qz_print_jobs')) {
+            return response()->json(['success' => false, 'message' => 'qz_print_jobs table not migrated'], 404);
+        }
+
+        $job = \DB::table('qz_print_jobs')->where('id', $id)->first();
+
+        if (! $job) {
+            return response()->json(['success' => false, 'message' => "Print job {$id} not found"], 404);
+        }
+
+        if (! in_array($job->status, ['pending', 'processing'], true)) {
+            return response()->json([
+                'success' => false,
+                'message' => "Print job {$id} is already {$job->status} and cannot be cancelled",
+            ], 409);
+        }
+
+        \DB::table('qz_print_jobs')->where('id', $id)->update([
+            'status'       => 'cancelled',
+            'processed_at' => now(),
+            'updated_at'   => now(),
+        ]);
+
         return response()->json([
             'success' => true,
             'message' => "Print job {$id} cancelled",
