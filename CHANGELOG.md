# Changelog

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/).

## [1.2.1] — 2026-08-30

Security, reliability, and idempotency patch release. Fully backward
compatible with v1.2.x — no breaking changes; one additive migration.

### Added
- `PATCH /qz/jobs/{id}` — dedicated job-status endpoint (`processing`,
  `completed`, `failed`) with the same ownership scoping as cancel
  (web + API surfaces).
- `GET /qz/printer?path=...` — query-param variant of the segment route;
  works on Apache where URL-encoded slashes (`%2F`) are rejected (404).
- `client_job_id` column on `qz_print_jobs` (additive migration
  `2026_08_30_000001`) — restores idempotency for bigint-mode installs.
- Eloquent models `QzPrintJob` and `QzPrinterPreference` with scopes.
- Laravel events: `PrintJobLogged`, `PrintJobStatusUpdated`.
- `qz:doctor` command — one-command installation health check (PHP/OpenSSL,
  certificate existence + expiry + key permissions, DB tables, config).
- `qz:prune-jobs` command — `qz_print_jobs` is finally prunable; supports
  `--older-than`, `--status`, `--keep`, `--dry-run`.
- Test suite (Orchestra Testbench + PHPUnit) covering boot, routes,
  certificate/signing, print upsert lifecycle, and printer preferences.
- GitHub Actions CI matrix (PHP 8.1–8.4 × Laravel 10–12, L13 canary,
  PHPStan baseline).
- `server_sync` config key — explicit switch for the server-side half of
  printer memory, bridged to the client via `window.QZ_CONFIG`.
- Configurable hotkey: `hotkey.enabled` / `hotkey.combination` are now
  honored by smart-print.js (previously hardcoded ctrl+shift+p).

### Fixed
- **Security — signing sample published to the public web root.** The
  `qz-assets` publish tag copied `resources/assets/signing/sign-message.php`
  (an unauthenticated `?request=` signer) into `public/vendor/qz-tray/assets/`,
  where php-fpm would EXECUTE it. Publishing is now file-by-file and excludes
  all PHP files and the whole `signing/` sample folder.
- **Security — IDOR in job cancellation.** `DELETE /qz/jobs/{id}` now scopes
  to the requesting identity (user id+type pair or device UUID); foreign ids
  return 404. Previously any session could cancel any job — sequential,
  guessable ids in bigint mode made this trivial.
- **Security — XSS in the printer-selection modal and failed-queue UI.**
  `smart-print.js` interpolated OS-provided printer names and job URLs into
  `innerHTML` unescaped (the same class as BUG-01, fixed in the blade view
  but still open in the library). Both sinks now build DOM nodes with
  `textContent`.
- **Print jobs stuck at `pending` / duplicated rows.** `POST /qz/print` is an
  UPSERT on the client job id (uuid mode: primary key; bigint mode: new
  `client_job_id` column). The SmartPrint client now reports
  `processing → completed|failed`, so queue views finally reflect reality and
  `processed_at` is stamped exactly once.
- **0-byte installer downloads.** The repo tracked empty placeholder
  `.exe/.deb/.pkg` files; `installer()` served them as valid downloads. It
  now requires `filesize() > 0` and falls back to the official download JSON.
- **Certificate auto-generation race.** Two concurrent workers could
  generate different key pairs and interleave writes, leaving cert/key
  mismatched and every signature failing. Generation now runs under an
  atomic cache lock and writes temp files + `rename()`, wrapped in
  try/catch so read-only storage degrades to a log error instead of a
  boot-time 500.
- **Hardcoded `/qz/` prefix in the client.** smart-print.js and the demo
  views read the prefix (or absolute URLs) from `window.QZ_CONFIG`, bridged
  from `config('qz-tray.routes.prefix')`.
- **`X-Device-Id` header not validated in `print()`.** Non-UUID garbage in
  the header crashed the insert into the uuid-typed column and silently
  degraded `db_logged`. The header is now validated like every other
  identity source.
- **`connectQZ()` concurrent-call race.** Callers polled `isActive()` after
  a 200ms nap and could push jobs to the offline buffer during a slow
  connect. Concurrent callers now share one in-flight connect promise.
- **`qz:launch` protocol spam.** The hidden-iframe launch fired on every
  retry and every 10s auto-reconnect tick (Firefox shows a native dialog
  each time). Now capped at 2 attempts per page load.
- **`GET /qz/printer/{path}` on Apache.** Page paths were sent
  URL-encoded (`%2F`), which Apache rejects by default; the client now uses
  the `?path=` route.
- **`testPdf()` TypeError with DomPDF.** Declared return type
  `Illuminate\Http\Response` does not cover DomPDF's `StreamedResponse`;
  widened to the Symfony parent type.
- **`setPrinter`/`getPrinter`/`clearCache` 500s before migration.** All
  printer-preference endpoints now degrade with a clear 503 JSON message,
  matching `print()`/`jobs()`.
- **Tenant id out of bigint range.** `99999999999999999999` passed
  validation and exploded in the INSERT; numeric ids are now bounded to the
  unsigned bigint range (clean 422 instead of a 500).
- **`/qz/status` reported version 1.0.0.** Now sourced from the provider's
  `VERSION` constant.
- **Redundant printer-memory syncs.** `rememberPrinter()` fired a POST on
  every print; it now syncs only when the effective choice changes.
- **Auto-print missed dynamically added elements.** `bind()` accepts a root
  element and an opt-in MutationObserver (`QZ_CONFIG.observeDom`) queues
  auto-print nodes injected after load.

### Changed
- `composer.json` no longer pins a `version` field — releases are git tags.
- Repository hygiene: removed `README_OLD.md`, `stracture.txt`,
  `folder_structure.txt`, `smart-print-old.js`, and the empty installer
  placeholders; added `.gitignore`, `phpunit.xml`, and CI.
