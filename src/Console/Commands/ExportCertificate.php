<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;

/**
 * qz:certificate:export — package the current QZ signing certificate and its
 * private key into ONE password-protected PKCS#12 (.pfx) file so the exact
 * same keypair can be installed on every subdomain of the same project.
 *
 * WHY THIS EXISTS
 * QZ Tray's "Untrusted website" prompt is keyed to the certificate
 * fingerprint, not to the domain. Every `qz:generate-certificate` run on a
 * different vhost produces a DIFFERENT self-signed certificate, so every
 * subdomain triggers its own trust prompt on every client machine. When all
 * subdomains present the SAME certificate, the user clicks "Always Allow"
 * once per machine and every subdomain connects from then on.
 *
 *   main domain:      php artisan qz:certificate:export
 *   transfer .pfx:    scp (never email/chat)
 *   each subdomain:   php artisan qz:certificate:import --pfx=... --password=...
 *
 * See docs/multi-domain.md for the full guide (including importing a real
 * CA-signed certificate, which removes the prompt entirely).
 */
class ExportCertificate extends Command
{
    protected $signature = 'qz:certificate:export
                            {--out= : Output .pfx path (default: storage/qz/qz-certificate-export.pfx)}
                            {--password= : PKCS#12 password (will prompt securely if omitted)}
                            {--force : Overwrite an existing .pfx file}';

    protected $description = 'Export the QZ Tray certificate + private key as a password-protected .pfx for sharing across subdomains';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled.');
            return 1;
        }

        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath  = config('qz-tray.key_path',  storage_path('qz/private-key.pem'));

        if (! is_file($certPath) || ! is_file($keyPath)) {
            $this->error('❌ Certificate or private key not found. Run: php artisan qz:generate-certificate');
            $this->line("  Certificate: {$certPath}");
            $this->line("  Private key: {$keyPath}");

            return 1;
        }

        $certPem = (string) file_get_contents($certPath);
        $keyPem  = (string) file_get_contents($keyPath);

        $cert = openssl_x509_read($certPem);
        $key  = openssl_pkey_get_private($keyPem);

        if (! $cert || ! $key) {
            $this->error('❌ Existing certificate or private key cannot be parsed. Re-run: php artisan qz:generate-certificate --force');

            return 1;
        }

        if (! openssl_x509_check_private_key($cert, $key)) {
            $this->error('❌ The private key does not match the certificate. Fix the pair (or re-run qz:generate-certificate --force) before exporting.');

            return 1;
        }

        $password = (string) ($this->option('password') ?: $this->secret('PKCS#12 password (min 4 chars)'));
        if (strlen($password) < 4) {
            $this->error('❌ Password must be at least 4 characters (OpenSSL PKCS#12 requirement).');

            return 1;
        }

        // Preserve any intermediate certificates bundled after the leaf, so
        // the chain survives the round-trip (matters when the source is a
        // fullchain.pem from a real CA).
        $extraCerts = $this->extractAdditionalCerts($certPem);

        $pkcs12 = null;
        $exportOptions = $extraCerts ? ['extracerts' => $extraCerts] : [];
        if (! openssl_pkcs12_export($cert, $pkcs12, $key, $password, $exportOptions)) {
            $err = openssl_error_string() ?: 'unknown error';
            $this->error("❌ Failed to build PKCS#12 container: {$err}");

            return 1;
        }

        $out = (string) ($this->option('out') ?: dirname($certPath).'/qz-certificate-export.pfx');

        if (is_file($out) && ! $this->option('force')) {
            $this->error("❌ Output file already exists: {$out}. Use --force to overwrite.");

            return 1;
        }

        $outDir = dirname($out);
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        if (file_put_contents($out, (string) $pkcs12) === false) {
            $this->error("❌ Could not write {$out} — check directory permissions.");

            return 1;
        }
        chmod($out, 0600);

        $this->info('✅ Certificate exported for subdomain sharing!');
        $this->line("  📦 Package:    {$out}");
        $this->line('  🔐 Protected:  PKCS#12, password required to import');
        $this->newLine();

        $this->showFingerprint($certPem);

        $this->newLine();
        $this->info('📋 Next steps (see docs/multi-domain.md):');
        $this->line('  1. Transfer the .pfx SECURELY — scp/rsync/SFTP only. Never email or chat it.');
        $this->line('  2. On each subdomain of this project, import it:');
        $this->line('       php artisan qz:certificate:import --pfx=/path/qz-certificate-export.pfx --password=...');
        $this->line('  3. Clients click "Always Allow" ONCE — every subdomain using this same');
        $this->line('     certificate is then trusted automatically (trust is per-certificate,');
        $this->line('     not per-domain).');
        $this->line('  ⚙️  Simpler alternative when all subdomains are on THIS server: point every');
        $this->line("     install's .env at these same files instead of copying:");
        $this->line("       QZ_CERT_PATH={$certPath}");
        $this->line("       QZ_KEY_PATH={$keyPath}");

        return 0;
    }

    /**
     * All certificate blocks after the first one (the leaf) — used as
     * extracerts when building the PKCS#12 container.
     *
     * @return array<int, string>
     */
    protected function extractAdditionalCerts(string $pem): array
    {
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $m) === false) {
            return [];
        }

        $blocks = $m[0] ?? [];

        return count($blocks) > 1 ? array_slice($blocks, 1) : [];
    }

    /**
     * Print SHA-1 (the format QZ Tray shows in its trust dialog) and SHA-256
     * fingerprints so users can verify all subdomains present the same cert.
     */
    protected function showFingerprint(string $certPem): void
    {
        $sha1   = openssl_x509_fingerprint($certPem, 'sha1');
        $sha256 = openssl_x509_fingerprint($certPem, 'sha256');

        if (! $sha1 || ! $sha256) {
            return;
        }

        $this->line('  🖐  Fingerprint (SHA-1, as shown in the QZ Tray dialog):');
        $this->line('      '.implode(':', str_split($sha1, 2)));
        $this->line('  🖐  Fingerprint (SHA-256):');
        $this->line('      '.implode(':', str_split($sha256, 2)));
        $this->line('  ℹ️  Compare this fingerprint on every subdomain via qz:doctor — identical');
        $this->line('     fingerprints = clients only need to trust once.');
    }
}
