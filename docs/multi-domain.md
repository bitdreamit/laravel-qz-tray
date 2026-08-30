# Multi-Subdomain & Trust Guide (v1.3.0)

## The problem you are seeing

QZ Tray shows a dialog like this the first time each site connects:

```
Validity:    Invalid Certificate
Organization: Bit Dream IT
Common Name:  Laravel QZ Tray
Trusted:      Untrusted website
Fingerprint:  5dbf3da475c4dd1bf07c68179cd1b9346ac2...
```

**This is not a broken certificate.** "Invalid Certificate" here means the
certificate is **self-signed** (issuer = subject), so QZ Tray cannot chain it
to a Certificate Authority it already trusts. QZ Tray therefore asks the user
to approve it by hand.

It re-appears for **every subdomain** because each install ran
`qz:generate-certificate` independently — every vhost got a **different**
self-signed certificate (a different fingerprint), and QZ Tray treats each
fingerprint as a new unknown publisher.

## The one rule that fixes everything

> **QZ Tray trust is keyed to the certificate FINGERPRINT — not to the
> domain.** The first time a client approves a certificate ("Always Allow"),
> every site presenting that same certificate is trusted from then on:
> other subdomains, other ports, other paths — no new prompt.

So there are only two ways the prompt goes away:

| Option | What you do | Client experience |
|---|---|---|
| **A. CA-signed certificate (recommended)** | Import your real HTTPS certificate (Let's Encrypt / cPanel AutoSSL) | **No prompt at all** — QZ Tray chains it to a public CA it already trusts |
| **B. One shared self-signed certificate** | Install the *same* keypair on every subdomain | Prompt **once per machine**, then all subdomains are trusted |

---

## Option A — Import a real CA-signed certificate (recommended)

Every public CA that QZ Tray recognizes (Let's Encrypt/ISRG, DigiCert,
Sectigo, Google Trust Services, …) is in its trust store. Import the
**fullchain** certificate of one of your subdomains — or better, a wildcard
cert covering `*.yourdomain.com` — and the trust dialog disappears entirely,
on every machine, forever.

### Let's Encrypt (certbot / acme.sh)

```bash
php artisan qz:certificate:import \
    --cert=/etc/letsencrypt/live/app.example.com/fullchain.pem \
    --key=/etc/letsencrypt/live/app.example.com/privkey.pem
```

Notes:

- Use **`fullchain.pem`**, never `cert.pem`. QZ Tray needs the intermediate
  CA to build the chain to the trusted root. The import command warns you if
  the file only contains the leaf.
- **Wildcard covers all subdomains in one cert**: request
  `*.example.com` with the DNS-01 challenge
  (`certbot certonly --dns-route53 -d "*.example.com"` or your provider's
  plugin), then every subdomain can import the same fullchain. Renewal is
  handled by your existing certbot timer — re-run the import command (with
  `--force`) after each renewal, e.g. from a deploy script or a
  `certbot --deploy-hook` that copies the files and runs the import.
- Your **web server keeps serving TLS from the original files** — importing
  copies the pair into `storage/qz/` (or your configured
  `QZ_CERT_PATH`/`QZ_KEY_PATH`). The site's HTTPS behavior is unchanged.

### cPanel / AutoSSL

AutoSSL certificates live under `/var/cpanel/ssl/apache_tls/<domain>/` (as
root). Combine the `certificate` and `certificate.key` files:

```bash
php artisan qz:certificate:import \
    --cert=/var/cpanel/ssl/apache_tls/app.example.com/certificate \
    --key=/var/cpanel/ssl/apache_tls/app.example.com/certificate.key
```

If AutoSSL issued a wildcard, all subdomains import the same pair.

### Security note

The QZ signing key can sign QZ Tray challenges for any site that presents
its certificate. Importing your TLS key gives `storage/qz/private-key.pem`
the same secrecy requirements as the TLS key itself: keep it `0600`, owned
by the PHP user, and never commit it to git. If you prefer full key
separation, use Option B.

---

## Option B — Share ONE self-signed certificate across all subdomains

Generate once, distribute once, trust once per machine.

### On the main domain

```bash
php artisan qz:generate-certificate --show
php artisan qz:certificate:export --out=/root/qz-shared.pfx
# enter a strong password when prompted
```

The export prints the SHA-1 fingerprint — the same value QZ Tray shows in
its dialog. Write it down; it is your cross-subdomain verification token.

### On each subdomain

Transfer the `.pfx` with `scp`/`rsync`/SFTP — **never email or chat it** — then:

```bash
php artisan qz:certificate:import --pfx=/root/qz-shared.pfx --password='…'
```

The import validates the pair, backs up any existing certificate, and prints
the fingerprint. If it matches the export fingerprint, the subdomain now
presents the identical certificate.

### On client machines

The **first** connection from any of your subdomains shows the trust dialog —
click **Always Allow** (QZ Tray ≥ 2.1 remembers the certificate). Every other
subdomain of the project then connects silently, because the fingerprint is
already trusted.

> Enterprise deployments can also pre-trust certificates at install time —
> see the qz.io wiki ("Pre-trusting Certificates") for packaging the trusted
> cert with the QZ Tray installer.

---

## Option C — All subdomains on the same server? Point at the same files

Since v1.3.0 both paths are env-overridable. If every subdomain is a Laravel
install on the same box (your case: `/home/advmedi/...`), copy nothing —
share the files:

```bash
# one-time, as root
mkdir -p /home/qz-shared
cp /home/advmedi/public_html/storage/qz/digital-certificate.txt /home/qz-shared/
cp /home/advmedi/public_html/storage/qz/private-key.pem         /home/qz-shared/
chown -R <php-user>:<php-group> /home/qz-shared
chmod 644 /home/qz-shared/digital-certificate.txt
chmod 640 /home/qz-shared/private-key.pem
```

Then in **each** subdomain's `.env`:

```dotenv
QZ_CERT_PATH=/home/qz-shared/digital-certificate.txt
QZ_KEY_PATH=/home/qz-shared/private-key.pem
```

and clear the config cache of each site:

```bash
php artisan config:clear && php artisan config:cache
```

Every vhost now reads the same keypair. `qz:doctor` on any subdomain shows
the shared fingerprint and marks `shared_path: true` in `/qz/status`.

> **open_basedir:** if a vhost restricts PHP's open paths, add
> `/home/qz-shared` to its `open_basedir` list, or fall back to Option B.

---

## Verifying across subdomains

On every subdomain run:

```bash
php artisan qz:doctor
```

Compare the **Certificate fingerprint (SHA-1)** row — it shows the same
colon-separated value the QZ Tray dialog displays. Identical fingerprints
across all subdomains = one trust decision covers them all.

You can also open `/qz/status` on each subdomain — the JSON now includes
`certificate_details.fingerprint_sha1`, `self_signed`, and `shared_path`.

## Rotation caveats

- Regenerating or re-importing a **different** certificate changes the
  fingerprint → every client machine sees the trust prompt **once more**
  (with a CA-signed cert: not at all).
- Renewing the **same** Let's Encrypt certificate (same domain list) keeps
  the same public/private key pair by default (`certbot` reuses the key), so
  renewals normally do **not** re-trigger prompts. If you ever regenerate
  with a fresh key, expect one prompt per machine.
- Old backups created by import (`*.bak-YYYYMMDD-HHMMSS`) still live in
  `storage/qz/` — delete them once the new cert is confirmed working.

## Troubleshooting the dialog

| Dialog field | Meaning |
|---|---|
| `Trusted: Untrusted website` | Self-signed / unknown CA — expected until you apply Option A or approve once |
| `Fingerprint` | Compare with `qz:doctor` on the server; must match across subdomains |
| `Signature: Not Required` | Normal for the initial `connect` handshake |
| Prompt re-appears every time | User clicked "Allow" (one-time) instead of "Always Allow"; or each site presents a different cert — apply this guide |
| Prompt after server migration | Cert regenerated with a new keypair — re-import the shared `.pfx` or CA cert |
