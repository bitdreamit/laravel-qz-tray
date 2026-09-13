# Changelog

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/).

## [1.4.2] — 2026-09-13

### Fixed
- **"Invalid zip" downloads of qz-client-bundle.zip.** Three independent
  causes, all addressed:
  1. `qz:client-bundle --zip` never checked `ZipArchive::close()` — the call
     that actually writes the archive — so a disk-quota / `open_basedir`
     failure silently left a corrupt (often zero-entry) archive on disk that
     clients then downloaded. `close()` is now verified, partial results are
     deleted, and the freshness stamp (`.built-stamp`) is written *after* the
     zip so the HTTP endpoint cannot mistake a corrupt archive for a fresh one.
  2. **New: pure-PHP zip fallback** (`Support\ZipBuilder`, STORE method,
     CRC-32 per entry, atomic rename, self-check before write). Hosts without
     `ext-zip` — very common on cPanel CLI builds — now produce a valid zip
     instead of skipping it, and a failing ZipArchive degrades gracefully
     instead of leaving garbage behind.
  3. `GET /qz/client-bundle` streamed the file through the output buffers,
     where a UTF-8 BOM, a PHP deprecation notice or Laravel Debugbar output
     could ride along and make Windows reject a zip that was fine on disk.
     The endpoint now discards every output buffer and serves a
     `BinaryFileResponse` (Content-Length included, `streamDownload` removed),
     so browsers fail loudly instead of saving truncated data.
- The `ext-zip` 503 gate on `GET /qz/client-bundle` was removed — serving a
  static file needs no extension, and builds are covered by the fallback.
- CLI output now prints the zip's size and SHA-256, so a download can be
  verified on Windows with `certutil -hashfile qz-client-bundle.zip SHA256`.

## [1.4.1] — 2026-09-13

### Fixed
- **HTTP bundle download could 500** ("command does not exist"): the service
  provider registered artisan commands only when `runningInConsole()`, so the
  on-demand rebuild inside `GET /qz/client-bundle` (triggered whenever the
  signing certificate changed since the last build) failed with
  `CommandNotFoundException` in web context. Commands are now registered
  unconditionally; the watcher auto-schedule stays console-only.
- `GET /qz/client-bundle` now falls back to serving the previously built zip
  when the on-demand rebuild throws (e.g. storage permission issues) instead
  of aborting with 500 — the CA trust root does not change on leaf rotation,
  so a stale bundle remains valid for client trust. Only aborts when no zip
  was ever built.

## [1.4.0] — 2026-09-13

**Zero-Prompt release.** Eliminates every QZ Tray dialog (browser TLS warning,
"Untrusted website / Allow" prompt, Chrome Local-Network-Access prompt) using
100% free mechanisms — no paid QZ certificate required. Fully backward
compatible: all new commands/options/endpoints are additive.

### Added
- `qz:generate-ca` — creates the project's own Root CA (`storage/qz/ca/`) with
  proper `CA:TRUE` / `keyCertSign` extensions. The CA certificate is what
  clients deploy as QZ Tray's `override.crt` (or `authcert.override=` /
  provision.json `type:"ca"`); leaves signed by it are then trusted by QZ Tray
  SILENTLY — the free equivalent of QZ Industries' paid signing certificate.
- `qz:generate-certificate` new options:
  - `--domain=*.example.com,example.com` — wildcard + SAN support (openssl.cnf
    `v3_leaf` profile with `subjectAltName`). One wildcard leaf covers every
    tenant subdomain → identical fingerprint everywhere → clients trust once.
    `certificate.san_domains` config / `QZ_SAN_DOMAINS` env sets it persistently.
  - `--ca` — signs the leaf with the local Root CA (falls back to self-signed
    with a warning when no CA exists). Backups of the previous pair are kept
    automatically (`.bak-<timestamp>`), and post-generation output now prints
    which trust path applies.
- `qz:override:export` — exports the file clients install as QZ Tray
  `override.crt` (the Root CA when the leaf chains to it, else the leaf), with
  printed deployment recipes for all four deployment mechanisms.
- `qz:client-bundle` — builds the Windows deployment bundle
  (`storage/qz/client-bundle/`): `override.crt`, `digital-certificate.txt`,
  **`qz-client-setup.ps1`** (admin script that silently installs QZ Tray's
  localhost root into the Windows Root store via certutil, deploys
  `override.crt`, adds the site certificate to allowed.dat via
  `qz-tray-console.exe --allow`, sets the Chrome/Edge
  `LocalNetworkAccessAllowedForUrls` policy, and restarts QZ Tray),
  `setup.bat` launcher, `provision.json` for QZ Tray 2.2.4+ sideloading
  (ca + cert + chromium/firefox LNA policy entries), and a README.
  `--zip` produces a distributable archive; `--no-lna`/`--no-allow`/
  `--lna-domains=` tune the steps; config `client_bundle.*` / env
  `QZ_LNA_DOMAINS` sets defaults.
- `qz:watch-certificate` — keeps the signing pair in sync with an external
  source (Let's Encrypt `fullchain.pem`/`privkey.pem`, cPanel AutoSSL,
  Cloudflare Origin). Detects source changes, validates the pair, backs up and
  atomically re-imports; without a source it reports expiry (exit 1 inside the
  30-day warn window). Auto-scheduled daily by the service provider when
  `certificate.watch.source_cert` is configured. This closes the last gap that
  made the Let's Encrypt path prompt-prone: stale leaves after renewals.
- Endpoints: `GET /qz/ca-certificate` (trust root download),
  `GET /qz/client-bundle` (auto-rebuilding zip download), **`GET /qz/setup`**
  (browser-facing Client Setup Wizard: server posture, fingerprint comparison,
  live QZ Tray WebSocket probe, copy-paste trust commands, download buttons).
- `/qz/status` and `POST /qz/setup` now report a `trust` summary:
  `mode` = `public-ca` | `own-ca` | `self-signed`, `root_ca_present`,
  `leaf_chains_to_root_ca` and the zero-prompt endpoint URLs.
- `docs/zero-prompt.md` — the complete free zero-prompt guide (three
  architectures, multi-tenant cheat sheet, rotation rules, file locations).
- Tests: `CaChainAndBundleTest` — CA/leaf chain generation, SAN round-trip,
  signature verification against the CA, fingerprint stability, atomic writes.

### Fixed
- `--domain` SAN parsing accepts wildcards (`FILTER_FLAG_HOSTNAME` rejects the
  leading `*.` — now validated on the bare domain and re-attached).

### Notes
- The zero-prompt model: QZ Tray trust is (1) keyed to certificate
  fingerprints, not domains; (2) granted silently to anything chaining to a
  public root or to `%PROGRAMFILES%\QZ Tray\override.crt`; (3) separately
  affected by Chrome 138+ Local Network Access. See docs/zero-prompt.md §0.

## [1.3.0] — 2026-08-30

Multi-subdomain certificate sharing + fully self-hosted JS. Fully backward
compatible — additive commands and config keys only.

### Added
- **The minified QZ Tray library now ships with the package**: `resources/js/qz-tray.min.js`
  is the genuine upstream 2.2.6 (jsDelivr/Terser build, byte-verified against
  `https://cdn.jsdelivr.net/npm/qz-tray@2.2.6/qz-tray.min.js`), tracked in git and
  published by the `qz-assets` tag. Previously it existed only as an untracked
  manual copy in some working clones — fresh installs (and other subdomain
  servers) hit a 404 on `vendor/qz-tray/js/qz-tray.min.js`. The unminified
  `qz-tray.js` remains shipped as the readable source; bump both together when
  upgrading QZ Tray (the BUG-26 lesson).
- `qz:certificate:export` — packages the current cert + private key into one
  password-protected PKCS#12 (.pfx) for secure transfer to other subdomains;
  preserves intermediate chains; prints the SHA-1 fingerprint (same value the
  QZ Tray trust dialog shows) and SHA-256.
- `qz:certificate:import` — installs an existing pair as the QZ signing pair.
  Accepts a PEM pair (`--cert=`/`--key=`, e.g. a Let's Encrypt
  `fullchain.pem` + `privkey.pem` — removes the "Untrusted website" prompt
  entirely) or an exported `.pfx` (`--pfx=`/`--password=` — one shared
  self-signed keypair across all subdomains = "Always Allow" clicked once per
  machine). Validates cert↔key match and expiry, warns on leaf-only chains,
  backs up the previous pair, prints tailored trust guidance.
- `docs/multi-domain.md` — full guide: why the prompt appears per subdomain
  (trust is keyed to certificate fingerprint, not domain), CA-cert import for
  zero prompts, shared self-signed keypair for one-prompt setups, and
  `QZ_CERT_PATH`/`QZ_KEY_PATH` shared-file mode for same-server vhosts.
  Includes a **Cloudflare section**: how a free Cloudflare Origin Certificate
  (wildcard, up to 15 years) doubles as the shared QZ signing pair — one
  "Always Allow" per client machine — and why it cannot silence the dialog
  the way a publicly trusted cert does.
- `/qz/status` now reports `certificate_details`: subject/issuer CN,
  `self_signed` flag, SHA-1 fingerprint, validity end, and whether a shared
  `QZ_CERT_PATH` is in use — for verifying all subdomains present the same
  keypair.
- `qz:doctor` now shows the certificate fingerprint, issuer, self-signed
  status, and a certificate↔key match check with actionable remediation text.

### Fixed
- **qz:install printed a self-hosted script that 404'd on fresh installs, and
  kept the CDN line (double-load).** The next-steps output suggested loading
  the CDN *and* `vendor/qz-tray/js/qz-tray.min.js` — the latter was not tracked
  in git, so following the printed instructions 404'd the QZ Tray library on
  fresh installs and double-loaded it where both lines were kept. Output now
  shows ONLY the self-hosted pair (`vendor/qz-tray/js/qz-tray.min.js` +
  `smart-print.js`), and the minified file actually ships (see Added).
- `smart.blade.php` loaded the QZ Tray library from the jsDelivr CDN —
  breaks offline/LAN/intranet installs and pins an uncontrolled third-party
  origin. Now self-hosted from the published package assets, consistent with
  `default.blade.php`/`example.blade.php`.
- `smart-print.js` init-failed warning referenced the non-existent
  `qz-tray.min.js`; it now prints the actual self-hosted path.

### Security
- Private-key hygiene in the new commands: exported `.pfx` files are written
  `0600`, imported keys `0600`, backups `0600`; export/import refuse
  mismatched cert↔key pairs; import refuses expired certificates.

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
