<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Bitdreamit\QzTray\Support\CertKit;
use Illuminate\Console\Command;

/**
 * qz:generate-ca — create the project's OWN root CA for zero-prompt trust.
 *
 * The CA certificate becomes QZ Tray's override.crt on every Windows client.
 * Once deployed, ANY leaf certificate signed by this CA (including the site
 * certificate produced by `qz:generate-certificate --ca`) is silently trusted
 * by QZ Tray — the "Untrusted website / Allow" prompt never appears again.
 *
 * This is the 100% free alternative to QZ Industries' paid signing
 * certificate. It mirrors what their portal sells: a root you control and a
 * leaf your server signs with. See docs/zero-prompt.md for the full guide.
 */
class GenerateCa extends Command
{
    protected $signature = 'qz:generate-ca
                            {--force : Replace an existing CA (invalidates trust on every client that already deployed it)}
                            {--show  : Show CA details after generation}';

    protected $description = 'Generate the local Root CA used for zero-prompt QZ Tray trust (deployed to clients as override.crt)';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled.');
            return 1;
        }

        $caCertPath = (string) (config('qz-tray.certificate.ca.cert_path') ?: storage_path('qz/ca/qz-root-ca.crt'));
        $caKeyPath  = (string) (config('qz-tray.certificate.ca.key_path')  ?: storage_path('qz/ca/qz-root-ca.key'));

        if (is_file($caCertPath) && is_file($caKeyPath) && ! $this->option('force')) {
            $this->info('✅ Root CA already exists. Use --force to replace it.');
            $this->line('  CA certificate: '.$caCertPath);
            $this->line('  CA private key: '.$caKeyPath);
            $this->newLine();
            $this->warn('⚠️  Replacing the CA invalidates trust on every client that already deployed override.crt.');

            if ($this->option('show')) {
                $this->showDetails($caCertPath);
            }

            return 0;
        }

        $config = (array) config('qz-tray.certificate', []);
        $bits   = (int)  ($config['key_bits'] ?? 2048);
        $days   = (int)  ($config['ca']['validity_days'] ?? $config['validity_days'] ?? 7300);
        $digest = CertKit::safeDigest((string) ($config['algorithm'] ?? 'sha256'));

        $subject = (array) ($config['subject'] ?? []);
        // The CA gets its own identity so leaf and CA are visually distinct
        // in QZ Tray's dialog and in every certificate viewer.
        $subject['commonName'] = (($subject['organizationName'] ?? 'Laravel QZ Tray')).' Root CA';

        $this->info('🔐 Generating QZ Tray Root CA...');

        $keyConfig = [
            'digest_alg'       => $digest,
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $this->line('  Generating CA private key ('.$bits.' bit)...');
        $caKey = openssl_pkey_new($keyConfig);

        if (! $caKey) {
            $this->error('❌ Failed to generate CA private key: '.(openssl_error_string() ?: 'unknown error'));
            return 1;
        }

        if (! openssl_pkey_export($caKey, $caKeyPem, null, $keyConfig)) {
            $this->error('❌ Failed to export CA private key: '.(openssl_error_string() ?: 'unknown error'));
            return 1;
        }

        $this->line('  Creating CA CSR...');
        $csr = openssl_csr_new($subject, $caKey, $keyConfig);

        if (! $csr) {
            $this->error('❌ Failed to create CA CSR: '.(openssl_error_string() ?: 'unknown error'));
            return 1;
        }

        // Sign the CA with itself + the v3_ca extensions (CA:TRUE, keyCertSign).
        $confPath = CertKit::writeTempConf(CertKit::caConf());

        try {
            $this->line('  Self-signing CA certificate ('.$days.' days)...');
            $caCert = openssl_csr_sign(
                $csr,
                null,
                $caKey,
                $days,
                [
                    'digest_alg'       => $digest,
                    'x509_extensions'  => 'v3_ca',
                    'config'           => $confPath,
                ],
                time()
            );
        } finally {
            CertKit::cleanup($confPath);
        }

        if (! $caCert) {
            $this->error('❌ Failed to self-sign the CA: '.(openssl_error_string() ?: 'unknown error'));
            return 1;
        }

        openssl_x509_export($caCert, $caCertPem);

        CertKit::writeAtomic($caCertPath, $caCertPem, 0644);
        CertKit::writeAtomic($caKeyPath, $caKeyPem, 0600);

        $this->newLine();
        $this->info('✅ Root CA generated successfully!');
        $this->line('  📄 CA certificate: '.$caCertPath);
        $this->line('  🔑 CA private key: '.$caKeyPath.'   (chmod 0600, NEVER serve or commit this file)');
        $this->line('  ⏳ Validity: '.$days.' days ('.round($days / 365).' years)');

        if ($this->option('show')) {
            $this->showDetails($caCertPath);
        }

        $this->newLine();
        $this->info('Next steps:');
        $this->line('  1. php artisan qz:generate-certificate --ca --domain=*.yourdomain.com,yourdomain.com');
        $this->line('  2. php artisan qz:client-bundle');
        $this->line('  3. Run the generated qz-client-setup.ps1 on every Windows client (or deploy via GPO).');
        $this->line('  Full guide: docs/zero-prompt.md');

        return 0;
    }

    protected function showDetails(string $caCertPath): void
    {
        $parsed = openssl_x509_parse((string) file_get_contents($caCertPath));

        if (! $parsed) {
            $this->warn('Could not parse CA certificate details.');
            return;
        }

        $this->newLine();
        $this->info('📋 Root CA Details:');
        $this->line('  Subject:     '.($parsed['subject']['CN'] ?? ($parsed['name'] ?? 'unknown')));
        $this->line('  Valid Until: '.date('Y-m-d H:i:s', $parsed['validTo_time_t'] ?? time()));
        $this->line('  Basic Constraints: '.($parsed['extensions']['basicConstraints'] ?? 'CA:TRUE'));

        $fp = CertKit::fingerprintSha1Pretty((string) file_get_contents($caCertPath));
        if ($fp) {
            $this->line('  Fingerprint (SHA-1): '.$fp);
        }
    }
}
