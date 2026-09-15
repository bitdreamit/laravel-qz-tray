# Changelog

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/).

## [1.5.5] — 2026-09-16

### Added
- **Lazy tray connection — the page no longer calls QZ Tray on load.**
  smart-print.js now connects on the FIRST PRINT (or Ctrl+Shift+Q), not at
  `DOMContentLoaded`. A desktop without the tray (or any phone) loads the
  page with **zero** websocket scans, zero `GET /qz/printer` calls and zero
  console errors. Opt back into the old behavior with
  `window.QZ_CONFIG.connectOnInit = true`.
- **Ctrl+Shift+Q — instant connection check.** One manual tray probe that
  overrides any cooldown: toast + console report — `QZ Tray connected —
  N printers · using X` or `QZ Tray NOT running — printing via the browser
  dialog`. Disable/override with
  `window.QZ_CONFIG.connectionHotkey = { enabled, combination }`. Also
  callable programmatically: `SmartPrint.connectionCheck()` (alias
  `checkConnection()`).
- **`@qzTrayScripts` Blade directive — automatic cache busting.** No more
  hand-edited `?time=` parameters or manual cache clears after every JS
  update. `@qzTrayScripts` in a layout emits `qz-tray.min.js` +
  `smart-print.js` with `?v={filemtime}`; `@qzTrayScripts(['js/lab-receipt.js'])`
  busts your own app files the same way. The query changes only when the
  file actually changes, so browser caching keeps working between deploys.

### Changed
- **QZ-unavailable cooldown — "call again and again" is gone.** After one
  full failed port scan the client marks the tray unavailable for 60s
  (`window.QZ_CONFIG.unavailableCooldownMs`): every print in that window
  goes STRAIGHT to the browser fallback — no reconnect storm, no repeated
  `/qz/printer` fetches, no page reload needed. When the cooldown expires
  the next print probes the tray once more, so starting QZ Tray mid-session
  silently re-enables direct printing (Ctrl+Shift+Q forces it immediately).
- **Background auto-reconnect is now opt-in** (`window.QZ_CONFIG.autoReconnect
  = true`). The print-time probe + cooldown fully replace it; the
  10s → 5min backoff ladder remains available for live status pages.
- **One QZ_CONFIG section controls everything (v1.5.5).** All 25 options the
  client understands now live in a single documented table (`QZ_DEFAULTS`) at
  the top of `smart-print.js`, applied through one accessor — `window.QZ_CONFIG`
  overrides per key, the table fills the rest. Defaults are silent-by-default:
  a page that sets nothing makes ZERO localhost calls on load, ZERO reconnect
  attempts when the tray is absent, ZERO dialogs and ZERO console errors.
  `SmartPrint.config()` prints the effective merged values for the current page.
- Offline-queued jobs (`fallbackMode: 'queue'`) now retry on the FIRST
  successful connection instead of at page load (lazy-connect companion).
- A hung handshake that degrades to the browser fallback
  (`connectTimeoutMs`) also arms the cooldown: the next print no longer
  waits for another hung `wss://localhost` handshake — it prints via the
  browser immediately.
- New `QZ_CONFIG.connectRetryDelayMs` (default 1500) configures the nap
  between connect retries; `SmartPrint.status()` now reports
  `unavailableForMs` + `connectOnInit`.

## [1.5.0] — 2026-09-14

### Added — "Universal Print API + Smart Actions" client layer (resources/js/smart-print.js)

- **Class binding — any button/link with `class="smart-print"` prints**:
  zero JS wiring — `<button class="smart-print" data-qz-url="/receipt/5.pdf">`,
  `<a class="smart-print" href="/receipt/5.pdf">`, `data-qz-urls="a.pdf|b.pdf"`
  (batch), `data-qz-html="<b>Hi</b>"`, `data-qz-element="#worklist-table"`,
  `data-qz-data="JVBERi0..."` (base64 / data: URI). Routing precedence:
  `data-qz-action` > element's own `onclick` (left entirely to that function —
  never double-prints, navigation suppressed) > urls > url > html > element >
  data > `<a href>` > silent console hint. Per-element overrides:
  `data-qz-printer`, `data-qz-copies`, `data-qz-profile`, `data-qz-type`,
  `data-qz-fetch`, `data-qz-filename`.
- **onclick function routing**: the built-ins are guarded `window` globals,
  so plain `onclick="printUrl('/receipt/5.pdf')"` or
  `onclick="printElement('#worklist', { copies: 2 })"` works with no other
  setup — class optional.
- **Generic names are BUILT-IN — zero setup**: `printUrl`, `printUrls`,
  `printPdf`, `printPdfs`, `printImage`, `printImages`, `printHtml`,
  `printElement`, `printElements`, `printPage`, `printAnyUrl`, `printAnyUrls`
  are registered automatically at load time — as `SmartPrint` methods, as
  guarded `window` globals, and as `data-qz-action` targets. No `define()`
  needed anymore; `SmartPrint.define({...})` stays for presets
  (printer/profile) and custom business names.
- **Browser-fetch hydration engine (the big fix)**: same-origin URLs are
  downloaded BY THE PAGE first (`fetch`, `credentials: 'same-origin'`), the
  response `Content-Type` picks the print path, and the bytes go to QZ Tray
  as base64. QZ itself has no session — this makes **auth-protected Laravel
  routes work for the first time**, including mPDF `->stream()` and
  `->download()` (Content-Disposition is ignored). Modes:
  `window.QZ_CONFIG.fetchMode = 'auto' (default, same-origin) | 'always' |
  'never'`; per call `printUrl(url, { fetch: true|false })`.
- **Misdirected-response guard**: a `.pdf`/image URL that answers
  `text/html` (login redirect, 404 page) or JSON is detected and NOT
  printed — the promise resolves `{ success: false, reason:
  'unexpected-response', contentType, status }` instead of feeding garbage
  to QZ or printing a login page. Unlabeled `application/octet-stream`
  responses are sniffed by magic bytes (`%PDF-`, PNG, JPEG, GIF).
- **Native image printing**: new `image` job type → QZ payload
  `{type:'image', format:'base64'|url}`; `printImage(url|dataURI|base64|
  element)`; browser-fallback iframe prints via an `<img onload=print>`
  document; `sniffType` now recognizes image extensions and raw
  base64 payloads (`iVBOR`/`/9j/`/`R0lGOD` prefixes).
- **`printPage()`** — snapshot the whole page (stylesheets cloned) and print
  via QZ html payload, or `{ mode: 'browser' }` for plain `window.print()`.
- **Batch buttons**: `data-qz-urls="u1|u2|u3"` (pipe-separated), plus
  `data-qz-type` and `data-qz-fetch` attribute overrides.
- **Fallback observability**: `window.QZ_CONFIG.onFallback(job)` hook and
  `QZ_CONFIG.notify = true` toast when a job degrades to the browser —
  silent by default, business flows never interrupted.
- **Robustness fixes**: raw HTML strings passed to `printHtml('<h1>…')` are
  recognized (not treated as URLs); base64-looking inputs are detected by
  magic prefix instead of falling through to relative-URL guesses;
  hydrated base64 bytes now survive a mid-print QZ failure into the
  fallback engines (`jobSpecForFallback` carries `base64`/`imageMime`);
  ArrayBuffer→base64 conversion is chunked (no stack overflow on large
  PDFs).

### Compatibility
- Legacy named actions (`printLabReceipt`/`printLabReceipts` and any custom
  `define()` names) keep working unchanged — they are thin aliases /
  overrides of the built-ins, and user-defined templates take precedence.
- `SmartPrint.printHTML`/`printPdf`/`printElement` keep their signatures;
  they are rerouted through the action runner so every call gets hydration
  + overrides + fallback parity.

### Added — "Smart Actions" client layer (same release)
- **Named actions, one call anywhere**: `SmartPrint.define({ printLabReceipt:
  { type: 'pdf', profile: 'a4' } })` registers the action AND auto-creates a
  guarded `window.printLabReceipt(...)` global (never overwrites an existing
  app function). Works via `SmartPrint.run(name, ...)`, `SmartPrint.<name>(...)`
  (Proxy), the window global, or plain buttons
  (`data-qz-action="printLabReceipt" data-qz-url="..."` / `data-qz-target="#el"`).
- **Any-input resolvers**: every action accepts a URL, css selector (#id /
  .class / any), HTMLElement, jQuery object, base64 string, `data:` URI,
  `{url|html|pdf|base64|selector|element|zpl|escpos|raw}`, a lazy `() => input`
  (resolved at call time), or an array of any of these (sequential batch).
  Element inputs snapshot the node plus the page stylesheets so printouts
  match the screen (`styles: false` to skip).
- **Never dead-ends — automatic browser fallback**: when QZ Tray is not
  installed / not connected / fails mid-print / the user cancels printer
  selection, the same call now degrades to the hidden-iframe browser print
  (new default `'auto'`) instead of parking the job and rejecting. Modes:
  `'auto' | 'iframe' | 'window' | 'newtab' | 'download' | 'queue' | 'none' |
  function`. Configure with `window.QZ_CONFIG.fallbackMode` or per action /
  per call; `'queue'` preserves the pre-1.5 offline-retry behavior. Iframe
  fallbacks serialize through one chain (browsers cannot stack print
  dialogs), with `onafterprint` + Safari-safe cleanup timers.
- **PDF base64 printing**: qz.print payload now accepts base64 bytes
  directly (`{type:'pdf', data}`) — no public URL round-trip needed;
  fallback engines convert base64 to blob URLs for the iframe/dialog paths.
- **Printer aliases**: `SmartPrint.aliasPrinter('receipt', 'XP-80C')` — views
  reference friendly names, real OS names live in one line; aliases resolve
  at PRINT time (re-mapping mid-batch works).
- **Health API**: `SmartPrint.status()` (library/connected/printers/queues/
  fallbackMode/actions) and `SmartPrint.whenReady(ms)` — a promise that
  settles with `{ok, reason}` as soon as tray availability is known.
- **Result contract**: promises now resolve `{ jobId, success, fallback?,
  cancelled?, reason? }` whenever a fallback produced output — no more
  "Uncaught (in promise)" noise on machines without the tray. Rejection is
  reserved for queue-mode and nothing-printable cases.
- Convenience globals (collision-guarded): `smartPrintHTML`, `smartPrintPdf`,
  `printElement`, `smartPrintRun`, `smartPrintDefine`; new methods
  `printHTML / printPdf / printElement / define / run / has / actions /
  aliasPrinter / whenReady / status`; `version` field. Existing API
  (`print / printZPL / printESC / data-qz-* attributes`) unchanged.
- New example: `resources/js/sample/smart-actions.lab.example.js` (lab /
  clinic receipt + worklist + label starter, copy-paste ready).

### Fixed
- **"Failed to get certificate: undefined" + blind SmartPrint on legacy
  app.js pages.** Bundles that ship their own QZ bootstrap open the
  websocket during their own evaluation — before `DOMContentLoaded`, so
  before `init()`/`connectQZ()` ever ran. QZ Tray's first challenge then
  found NO certificate promise (`callCert()` rejects bare → the warning),
  and `connectQZ()` early-returned on the already-active socket without
  discovering printers, so `SmartPrint.getPrinters()` stayed `[]` and the
  `'connected'` event never fired — even though `qz.printers.find()`
  clearly worked. Three-part fix in `smart-print.js`:
  1. certificate/sign resolvers are armed **immediately at library load**
     (and re-armed before any custom-opts native connect), so no handshake
     can hit an unregistered promise;
  2. the resolver never rejects with a bare `undefined` — real fetch
     errors surface with their actual message;
  3. **connection adoption**: an active-but-unowned socket is adopted on
     first contact (`connectQZ()`/`getPrinters()`) — resolvers re-asserted,
     printers discovered, remembered printer restored, `connected` +
     `printers-loaded` emitted, cached afterwards (no discovery churn).
- **`SmartPrint.getStatus()`** added as an alias of `status()` — users
  instinctively type `getStatus()`.
- **`GET /qz/test/pdf` now always serves a REAL PDF.** When
  `barryvdh/laravel-dompdf` was absent the endpoint fell back to a ~755-byte
  HTML page — QZ Tray downloads URLs ITSELF (no browser session), so every
  silent PDF print of the test document died with
  `Cannot parse (FILE)… as a PDF file: End-of-File, expected line at offset 755`
  (and the misdirected guard refused to print the HTML as a fallback). A
  built-in minimal PDF writer (pure PHP, byte-exact xref offsets, no
  dependencies) now produces the document when DomPDF is not installed.
- **Legacy `SmartPrint.print()` hydrates too.** `print(url)` and
  `print({ url, type: 'pdf', … })` previously bypassed the browser-fetch
  hydration engine, so QZ received the raw `(FILE)` URL and failed on
  session-protected routes with the same "Cannot parse" error. Both forms
  now ride the identical pipeline as `printUrl()`/`printPdf()`: type
  auto-detect, same-origin hydration (bytes instead of URL),
  misdirected-content guard (resolves `unexpected-response` + emits
  `job-failed`), printer/copies preserved. Data-carrying jobs
  (`zpl`/`escpos`/`raw`/base64) are untouched, and a non-OK fetch still
  degrades to the legacy URL behavior.

## [1.5.4] — 2026-09-14

Version bumped to **1.5.4** to match the deployed release line (1.5.1–1.5.3
were the earlier fix batches). Everything below ships in this release.

### Fixed
- **Embedded-PDF-in-URL rescue (`HTTP 414 URI Too Long`).** Blades that pass
  the mPDF base64 output as the route parameter produced URLs like
  `/lab/receipt/edit/JVBERi0xLjQ…` (hundreds of KB); pasting a base64 payload
  into a URL field produced the same class of monster
  (`https://…/qz/JVBERi…`). QZ Tray downloading such a URL dies with
  `Server returned HTTP response code: 414` (or a timeout / `Error writing to
  server`). SmartPrint now detects a `%PDF-` payload (`JVBERi…`) embedded in
  any URL path/query — string **and** object form — and prints those bytes
  directly instead of fetching the oversized URL, with three layers of
  defense: input resolution, the QZ payload builder (last-line), and
  `printReceipt()` in `lab-receipt.js`. The detector decodes **every** `%xx`
  escape and strips whitespace/newlines first, so URL-encoded and
  MIME-wrapped payloads are caught too.
- **Relative URLs handed to QZ (`PDF file specified could not be found.`).**
  When the browser-fetch hydration is skipped (non-OK response), the job
  degraded to the original RELATIVE URL — QZ Tray has no page context and
  cannot resolve it, failing with "PDF file specified could not be found".
  Every URL handed to QZ (pdf/html/image payloads) is now absolutized
  against the page origin (`blob:`/`data:`/absolute URLs pass through), so
  QZ can actually download it (public routes) or fail with the real error.
- **Hydration failures are now announced.** A silently skipped hydration
  (401/403/404/419/500 on the document fetch) made QZ's own download fail
  with a confusing message. It now warns once per job with the real HTTP
  status: `[SmartPrint] hydration skipped: <url> answered HTTP <status> —
  QZ Tray will download the URL itself (no session).`
- **Job logger no longer 422s on oversized payloads.** `POST /qz/print`
  validation caps `url` and `error_message` (newest controller: 2048/1000 —
  older deployments: 255/255), and the embedded-PDF URL above exceeded all
  of them, so every print attempt also sprayed `422 (Unprocessable
  Content)` into the console. `logPrintJob()` now truncates `url` and
  `error_message` to 240 chars **including** the `[truncated:N chars]`
  marker (fits the strictest cap), sets `metadata.url_truncated`, and drops
  base64 `data` bodies over 64 KB, recording only `metadata.data_bytes`.
- **Test console (`smart.blade.php`) accepts inline PDF payloads.** The PDF
  URL field now detects a raw base64 payload or a `data:application/pdf`
  URI and prints the bytes directly instead of building an oversized URL.
- **`printReceipt()` accepts the receipt PDF URL itself.** Blades can pass
  the `receiptPdf` route (`{{ route('lab.receipt.pdf', [$id]) }}` or
  `/lab/receipt/pdf/{id}/{format}`) as the first argument — it is printed
  as-is through the hydration engine (optionally carrying the
  `client`/`lab`/`office`/`duplicate` copy flag as a query parameter)
  instead of being glued into `lab/receipt/pdf-receipt/<url>`, which 404s.
  `printAllCopies()` and `printWorkList()` honor the same convention.
  Session-protected mPDF `->stream()` routes print because the bytes are
  fetched by the browser (with the session cookie) and handed to QZ —
  QZ Tray alone cannot download them (no session → login HTML →
  "Cannot parse … as a PDF file").
- **The browser fallback now works on EVERY call — not only after a page
  reload.** Three dead ends found and fixed. (1) `printIframe()` armed its
  cleanup timer INSIDE `onload`, so a frame that never fired `load` (blob:
  PDFs on mobile, blocked content, stalled network) never released the ONE
  serialized fallback queue — every later print silently queued behind it
  and only a reload printed again; a load watchdog armed BEFORE load
  (`QZ_CONFIG.fallbackLoadWatchdogMs`, default 20000) now guarantees the
  queue always advances. (2) On phones/tablets a hidden-iframe
  `window.print()` is a silent no-op — the 'auto' engine now routes mobile
  devices through the new-tab engine (the OS document viewer opens;
  print/share from there), with a programmatic-download then iframe safety
  net when the popup is blocked (`QZ_CONFIG.mobileFallbackMode` pins
  'newtab' | 'iframe' | 'download'). (3) `processQueue()` awaited the tray
  connect UNCONDITIONALLY — a hung mobile `wss://localhost:8181` handshake
  parked the first print forever with no error; the wait is now capped
  (`QZ_CONFIG.connectTimeoutMs`, default 8000) and the job degrades to the
  browser while the background attempt keeps running — if the tray comes up
  later, the next print is silent again.
- **CRITICAL: prints with no remembered printer crashed into the offline
  queue (`TypeError: Assignment to constant variable`).** `printQZ()`
  declared the resolved printer as `const` and then reassigned it inside the
  OS-default-printer branch (`printer = state._defaultPrinter`), so every
  print on a fresh browser profile, a cleared localStorage, a vanished
  printer or a pending server restore threw — processQueue swallowed the
  TypeError and parked the job in `sp_offline_queue` with a generic
  "QZ Tray offline" instead of printing or falling back. This was the real
  engine behind "print fails on Windows — no print until reload": the
  default-printer resolution (v1.5.0) had literally never worked. Now `let`.
- **A socket that is still CONNECTING is no longer adopted as "connected".**
  `qz.websocket.isActive()` also reports true while a legacy `app.js`
  bootstrap's handshake is still in flight; adoption then ran
  `qz.printers.find()` on that socket — instant throw on desktop, infinite
  hang on phones — and treated BOTH outcomes as a live tray: `qzReady`
  flipped true, a phantom `connected` event fired, `GET /qz/printer` was
  re-fetched, and the next print was routed into a dead socket instead of
  the browser fallback. Adoption now requires discovery to actually ANSWER
  (5s cap); a mid-handshake socket stays unadopted and prints degrade to the
  fallback they should have used.
- **Every QZ print is now time-capped (`QZ_CONFIG.printTimeoutMs`, default
  25000).** A tray socket that opened but never answers (auth stuck, dead
  spooler, half-finished handshake) used to hold `await printQZ(job)` — and
  with it the whole print-queue mutex — forever: later prints piled up
  silently and only a page reload printed again. The watchdog degrades the
  job to the browser fallback, closes the dead socket, and the NEXT print
  still goes through QZ once it answers. A single-dispatch guard
  (`dispatchFallback`) makes the watchdog and a late-waking printQZ
  mutually exclusive so a stalled receipt can never print twice.
- **The no-tray reconnect storm is gone.** The auto-reconnect tick used to
  call `connectQZ(1)` — a full port scan of all 8 candidate sockets, then a
  1.5s nap, then a SECOND scan — every 10 seconds forever (16 failed
  WebSockets per tick, the console wall of "WebSocket connection failed").
  Ticks now scan once (`retries=0`) and back off progressively per
  consecutive failure (10s → 30s → 60s → 2min → 5min cap); a success resets
  the ladder immediately, and a print click always scans fresh regardless
  of the ladder. `connectQZ()`'s default retries dropped 2 → 1 (the first
  fallback on a tray-less machine now fires in ~1.5s instead of ~4s), and
  `restorePrinter()` performs its `GET /qz/printer` server round-trip once
  per page instead of on every (phantom) reconnect.
- **Image jobs are printable by the browser fallback too.** `handleNoConnection()`
  treated only `pdf`/`html` as printable, so `printImage(...)` on a phone or
  tray-less machine was parked in the offline queue with a "QZ Tray offline"
  error instead of just printing; `image` specs now route through the same
  iframe/new-tab engines (`<img>` print document).

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
