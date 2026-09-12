# Zero-Prompt QZ Tray (v1.4.0) — the complete free guide

**Goal:** print from any tenant subdomain with **zero dialogs** — no browser TLS
warning, no QZ Tray "Untrusted website / Allow" prompt, no Chrome "local network
access" permission — **without paying QZ Industries for a signing certificate**.

Companion deep-dive (with diagrams and full root-cause analysis): see the
package PDF guide. This file is the practical, copy-paste version.

---

## 0. Why prompts appear at all (60-second model)

QZ Tray makes **three** independent trust decisions. Your users were hitting
all three:

| # | Prompt | Decision maker | Trigger |
|---|--------|----------------|---------|
| 1 | Browser: "Your connection is not private" for `wss://localhost:8181` | Browser trust store | QZ Tray's self-signed localhost TLS cert (`root-ca.crt`) |
| 2 | QZ Tray dialog: "Untrusted website — Allow / Always Allow?" | QZ Tray (Java) | Your site certificate not chaining to a root QZ Tray trusts |
| 3 | Chrome 138+: "site wants to access your local network" | Browser policy | Public page connecting to localhost (Local Network Access) |

**Key facts that make this solvable for free:**

- The transport connection is **always** `wss://localhost:8181` — the TLS cert
  only ever names `localhost`. Tenant subdomains are irrelevant at this layer.
- QZ Tray's signing prompt is keyed to the **certificate fingerprint**, not the
  domain. Identical cert on every tenant → one trust decision covers all.
- QZ Tray (2.1+) silently trusts **any** certificate chaining to:
  - a **public CA root** (Let's Encrypt, cPanel AutoSSL, …) — zero deployment, or
  - the root at `%PROGRAMFILES%\QZ Tray\override.crt` — **your own CA**, free.
- Every decision can be deployed **silently** on Windows via scripts/GPO.

---

## 1. The three zero-prompt architectures (pick ONE for the signing layer)

### Path A — Let's Encrypt / cPanel AutoSSL (real CA, zero client deployment)

Serve a publicly-trusted certificate from `/qz/certificate`. QZ Tray trusts it
silently. Nothing to install on clients for the signing layer.

```bash
# one-time: import the pair your web server already uses
php artisan qz:certificate:import --cert=/etc/letsencrypt/live/app.example.com/fullchain.pem \
                                    --key=/etc/letsencrypt/live/app.example.com/privkey.pem

# never think about renewals again (auto-scheduled once watch paths are set):
#   .env → QZ_WATCH_CERT=/etc/letsencrypt/live/app.example.com/fullchain.pem
#          QZ_WATCH_KEY=/etc/letsencrypt/live/app.example.com/privkey.pem
php artisan qz:watch-certificate     # re-imports after each LE renewal
```

Renewal note: when certbot renews, the leaf changes but stays chained to the
same public root (ISRG Root X1) — QZ Tray keeps trusting it with **no prompt**.
Run `qz:watch-certificate` daily (auto-scheduled by v1.4.0) so `/qz/certificate`
never serves a stale leaf.

Best when: your servers have working ACME/AutoSSL. Limitation: wildcard LE
certs need DNS-01; a per-host cert is fine because QZ Tray trust is
fingerprint-keyed — but then every host must import the same way and renewals
change fingerprints per host (still silent: they chain to the public root).

### Path B — Own Root CA + override.crt (recommended with your admin access)

`qz:generate-ca` creates **your own** root. Clients deploy that root **once**
as QZ Tray's `override.crt`. Every leaf you sign with it — including a wildcard
leaf covering all tenants — is then trusted **silently**. Rotating leaves never
re-touch clients as long as the root stays the same.

```bash
php artisan qz:generate-ca
php artisan qz:generate-certificate --force --ca --domain=*.example.com,example.com
php artisan qz:client-bundle --zip          # or download at GET /qz/client-bundle
# → run setup.bat as admin on each Windows client (or GPO/Intune the .ps1)
```

Best when: you control client machines (you do), or ACME is impossible
(offline LANs, internal-only domains, shared hosting without shell).

### Path C — Self-signed + "Always Allow" (zero extra infra)

Keep the plain self-signed cert, make sure **all tenants serve the identical
pair** (v1.3.0 export/import / shared `QZ_CERT_PATH`), and have users click
**"Always Allow" / "Remember this decision"** — once per machine. That stores
the cert in `%APPDATA%\qz\allowed.dat` and the prompt never returns for that
fingerprint. The historical bug was users clicking plain **Allow** (session-only).

Zero-prompt, zero-deploy variant of C: export the leaf itself as
`override.crt` (`qz:override:export` does this when no CA exists) — but prefer
Path B so future leaf rotations don't require re-touching clients.

---

## 2. The transport layer (fix for prompt #1) — once per client machine

QZ Tray generates its own `root-ca.crt` for `wss://localhost:8181` at install
time (`C:\ProgramData\qz\root-ca.crt`). Chrome/Edge use the **Windows trust
store**, so:

```bat
:: as Administrator, on each print client:
certutil -addstore -f Root "C:\ProgramData\qz\root-ca.crt"
:: then restart QZ Tray
```

QZ Tray's own installer usually does this; the v1.4.0 bundle re-asserts it
idempotently, so it is never the reason a cashier sees a red warning again.
(Firefox users: set `security.enterprise_roots.enabled = true` or use the
Firefox policy from the bundle's provision.json.)

## 3. The Chrome LNA layer (fix for prompt #3)

Chrome 138+ asks "…wants to access your local network" the first time a public
site talks to localhost. Users can click Allow-once, or you can pre-approve
your domains with a policy (the bundle adds this automatically):

```
HKLM\SOFTWARE\Policies\Google\Chrome\LocalNetworkAccessAllowedForUrls (REG_SZ)
   = ["*.example.com","example.com"]
HKLM\SOFTWARE\Policies\Microsoft\Edge\LocalNetworkAccessAllowedForUrls (REG_SZ)
   = ["*.example.com","example.com"]
```

QZ Tray 2.2.6's provisioning supports the same via `provision.json`
(`type: "policy"`, `app: "chromium"|"firefox"`) — included in the bundle.

---

## 4. Multi-tenant cheat sheet (one root domain, N tenants)

| Layer | What every tenant gets | Result |
|-------|------------------------|--------|
| Transport | Nothing changes — all tenants connect to the same `wss://localhost:8181` | One TLS trust per machine |
| Signing cert | The **same** wildcard leaf (`*.example.com`) + same key via `QZ_CERT_PATH`/`QZ_KEY_PATH` or `qz:certificate:export/--pfx` import | One fingerprint, one trust decision |
| Signing trust | One `override.crt` (your CA) per machine, or LE-chain = nothing | Zero prompts |
| LNA policy | One registry value listing `[*.]example.com` | Zero prompts |

Setup on the server (per tenant vhost), with the shared files approach:

```bash
# on the main host only:
php artisan qz:generate-ca
php artisan qz:generate-certificate --force --ca --domain=*.example.com,example.com

# every tenant vhost .env (same server: nothing to copy):
QZ_CERT_PATH=/home/shared/qz/digital-certificate.txt
QZ_KEY_PATH=/home/shared/qz/private-key.pem

# verify every tenant presents the identical fingerprint:
php artisan qz:doctor
curl -s https://tenant1.example.com/qz/status | grep fingerprint
```

## 5. Rotation & expiry rules that keep prompts away

- **Leaf rotation under the same CA (Path B):** clients notice nothing — trust
  is pinned to the root in override.crt.
- **Let's Encrypt renewal (Path A):** silent while the chain roots are trusted
  (ISRG Root X1); keep the watcher scheduled.
- **CA rotation (Path B):** the ONLY operation that re-introduces a one-time
  prompt per machine. Redeploy the new override.crt via the bundle/GPO *before*
  switching the leaf, or accept one "Always Allow" per machine.
- `qz:doctor` fails when the leaf has <30 days left — wire it into monitoring.

## 6. Command reference (v1.4.0 additions)

| Command | Purpose |
|---|---|
| `qz:generate-ca` | Create the local Root CA (override.crt source) |
| `qz:generate-certificate --ca --domain=*.x.com,x.com` | Wildcard leaf chained to the local CA |
| `qz:override:export --show` | Export the file clients deploy as override.crt |
| `qz:client-bundle --zip` | Build the Windows deployment bundle (+ `.zip`) |
| `qz:watch-certificate` | Re-import LE/AutoSSL renewals; expiry guard |
| `qz:certificate:export` / `qz:certificate:import` | v1.3.0 cross-vhost pair sharing (still valid) |
| `qz:doctor` | Health check incl. fingerprint + expiry warnings |

New HTTP endpoints (both gated by config, serve public material only):
`GET /qz/ca-certificate`, `GET /qz/client-bundle`, `GET /qz/setup` (wizard).

## 7. QZ Tray file locations (Windows clients)

| File | Path | Purpose |
|------|------|---------|
| `root-ca.crt` (transport) | `C:\ProgramData\qz\root-ca.crt` | TLS for wss://localhost:8181 |
| `override.crt` (signing root) | `C:\Program Files\QZ Tray\override.crt` | Replaces QZ's trust root with yours |
| `allowed.dat` | `C:\Users\<user>\AppData\Roaming\qz\allowed.dat` | Per-user "Always Allow" entries |
| `qz-tray.properties` | `C:\Program Files\QZ Tray\qz-tray.properties` | `authcert.override=/path/to/override.crt` |
| provision sideload | `C:\Program Files\QZ Tray\provision\provision.json` | 2.2.4+ provisioning (ca/cert/policy) |
| CLI whitelist | `"C:\Program Files\QZ Tray\qz-tray-console.exe" --allow cert.pem` | Adds cert to allowed.dat |
| CLI transport cert | `qz-tray-console.exe certgen --key privkey.pem --cert fullchain.pem` | Print-server / custom-host TLS |
