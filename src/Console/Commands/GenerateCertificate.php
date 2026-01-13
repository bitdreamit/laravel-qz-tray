<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateCertificate extends Command
{
    protected $signature = 'qz-tray:generate-certificate {--force : Force regeneration}';
    protected $description = 'Generate SSL certificate for QZ Tray';

    public function handle()
    {
        $certPath = config('qz-tray.cert_path', storage_path('qz/certificate.pem'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        if (file_exists($certPath) && file_exists($keyPath) && !$this->option('force')) {
            if (!$this->confirm('Certificate already exists. Regenerate?', false)) {
                $this->info('Certificate generation cancelled.');
                return;
            }
        }

        $this->info('🔐 Generating SSL certificate...');

        // Create directory if not exists
        @mkdir(dirname($certPath), 0755, true);

        $config = [
            'digest_alg' => 'sha512',
            'private_key_bits' => 4096,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Generate keypair
        $keypair = openssl_pkey_new($config);
        if (!$keypair) {
            $this->error('Failed to generate key pair');
            return;
        }

        // Export private key
        openssl_pkey_export($keypair, $privateKey);

        // Create CSR
        $dn = [
            'countryName' => $this->ask('Country (US)', 'US'),
            'stateOrProvinceName' => $this->ask('State/Province', 'California'),
            'localityName' => $this->ask('Locality', 'San Francisco'),
            'organizationName' => $this->ask('Organization', 'Laravel QZ Tray'),
            'commonName' => $this->ask('Common Name (domain)', 'qz-tray.local'),
            'emailAddress' => $this->ask('Email', 'admin@example.com'),
        ];

        $csr = openssl_csr_new($dn, $keypair, $config);

        // Sign certificate (self-signed for 10 years)
        $cert = openssl_csr_sign($csr, null, $keypair, 3650, $config, time());

        // Export certificate
        openssl_x509_export($cert, $certificate);

        // Get fingerprint
        $fingerprint = openssl_x509_fingerprint($cert, 'sha256');

        // Save files
        file_put_contents($certPath, $certificate);
        file_put_contents($keyPath, $privateKey);

        $this->info('✅ Certificate generated successfully!');
        $this->line('');
        $this->line('📄 Certificate Details:');
        $this->line('   Path: ' . $certPath);
        $this->line('   SHA-256 Fingerprint: ' . $fingerprint);
        $this->line('   Valid for: 10 years');
        $this->line('   Key Size: 4096 bits');
        $this->line('');
        $this->line('🔧 Remember to restart your web server if needed.');
    }
}
