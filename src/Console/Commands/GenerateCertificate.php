<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;

class GenerateCertificate extends Command
{
    protected $signature = 'qz-tray:generate-certificate {--force : Force regeneration of certificate}';

    protected $description = 'Generate SSL certificate for QZ Tray';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('OpenSSL PHP extension is not enabled.');

            return self::FAILURE;
        }

        $certPath = config('qz-tray.cert_path', storage_path('qz/certificate.pem'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        if (file_exists($certPath) && file_exists($keyPath) && ! $this->option('force')) {
            if (! $this->confirm('Certificate already exists. Regenerate?', false)) {
                $this->info('Certificate generation cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info('Generating SSL certificate for QZ Tray...');

        @mkdir(dirname($certPath), 0755, true);

        $opensslConfig = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Generate private key
        $keypair = openssl_pkey_new($opensslConfig);

        if ($keypair === false) {
            $this->error('Failed to generate OpenSSL private key.');
            $this->line(openssl_error_string());

            return self::FAILURE;
        }

        // Export private key
        if (! openssl_pkey_export($keypair, $privateKey)) {
            $this->error('Failed to export private key.');
            $this->line(openssl_error_string());

            return self::FAILURE;
        }

        // Certificate identity
        $dn = [
            'countryName' => $this->ask('Country', 'US'),
            'organizationName' => $this->ask('Organization', 'Laravel QZ Tray'),
            'commonName' => $this->ask('Common Name (domain)', 'qz-tray.local'),
        ];

        // Create CSR
        $csr = openssl_csr_new($dn, $keypair, $opensslConfig);

        if ($csr === false) {
            $this->error('Failed to create certificate signing request.');

            return self::FAILURE;
        }

        // Self-signed certificate (10 years)
        $cert = openssl_csr_sign($csr, null, $keypair, 3650);

        if ($cert === false) {
            $this->error('Failed to sign certificate.');

            return self::FAILURE;
        }

        openssl_x509_export($cert, $certificate);

        // Save files
        file_put_contents($certPath, $certificate);
        file_put_contents($keyPath, $privateKey);

        // ✅ PHP 8.0 + 8.1+ safe cleanup
        if (is_resource($keypair)) {
            openssl_pkey_free($keypair);
        }

        if (is_resource($cert)) {
            openssl_x509_free($cert);
        }

        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        $this->info('Certificate generated successfully.');
        $this->line('');
        $this->line('Certificate Path   : '.$certPath);
        $this->line('Private Key Path   : '.$keyPath);
        $this->line('SHA-256 Fingerprint: '.$fingerprint);
        $this->line('Validity           : 10 years');
        $this->line('Key Size           : 2048 bits');
        $this->line('');
        $this->line('Restart your web server if required.');

        return self::SUCCESS;
    }
}
