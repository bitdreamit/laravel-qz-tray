<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;

class GenerateCertificate extends Command
{
    protected $signature = 'qz:generate-certificate
                            {--force : Force generation even if certificate exists}
                            {--show : Show certificate details after generation}';

    protected $description = 'Generate SSL certificate for QZ Tray in demo format';

    public function handle()
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled. Please enable it in your PHP configuration.');

            return 1;
        }

        // Get paths from config
        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        // Check if already exists
        if (file_exists($certPath) && file_exists($keyPath) && ! $this->option('force')) {
            $this->info('✅ Certificate already exists.');
            $this->line('Certificate path: '.$certPath);
            $this->line('Private key path: '.$keyPath);

            if ($this->option('show')) {
                $this->showCertificateDetails($certPath);
            }

            return 0;
        }

        $this->info('🔐 Generating QZ Tray certificate in demo format...');

        // Get certificate config
        $certConfig = config('qz-tray.certificate', []);

        // Configuration
        $config = [
            'digest_alg' => $certConfig['algorithm'] ?? 'sha256',
            'private_key_bits' => $certConfig['key_bits'] ?? 2048,
            'private_key_type' => $certConfig['key_type'] ?? OPENSSL_KEYTYPE_RSA,
        ];

        // Generate private key
        $this->line('Generating private key...');
        $privateKey = openssl_pkey_new($config);
        if (! $privateKey) {
            $this->error('❌ Failed to generate private key: '.openssl_error_string());

            return 1;
        }

        // Export private key
        openssl_pkey_export($privateKey, $privateKeyPEM);

        // Get subject from config
        $subject = $certConfig['subject'] ?? [
            'countryName' => 'US',
            'stateOrProvinceName' => 'NY',
            'localityName' => 'Canastota',
            'organizationName' => 'QZ Industries, LLC',
            'organizationalUnitName' => 'QZ Industries, LLC',
            'commonName' => 'QZ Tray Demo Cert',
            'emailAddress' => 'support@qz.io',
        ];

        $this->line('Creating certificate...');

        // Create CSR
        $csr = openssl_csr_new($subject, $privateKey, $config);
        $validityDays = $certConfig['validity_days'] ?? 7300;
        $cert = openssl_csr_sign($csr, null, $privateKey, $validityDays, $config, time());

        // Export certificate
        openssl_x509_export($cert, $certificatePEM);

        // Create directory if needed
        $certDir = dirname($certPath);
        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
            $this->line('Created directory: '.$certDir);
        }

        // Save files
        file_put_contents($certPath, $certificatePEM);
        file_put_contents($keyPath, $privateKeyPEM);

        // Set permissions
        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        $this->info('✅ Certificate generated successfully!');
        $this->line('📄 Certificate path: '.$certPath);
        $this->line('🔑 Private key path: '.$keyPath);
        $this->line('⏳ Validity: '.$validityDays.' days');

        if ($this->option('show')) {
            $this->showCertificateDetails($certPath);
        }

        // Test the certificate
        $this->testCertificate();

        openssl_free_key($privateKey);

        return 0;
    }

    protected function showCertificateDetails($certPath)
    {
        $certData = openssl_x509_parse(file_get_contents($certPath));

        $this->newLine();
        $this->info('📋 Certificate Details:');
        $this->line('Subject: '.$certData['name']);
        $this->line('Valid From: '.date('Y-m-d H:i:s', $certData['validFrom_time_t']));
        $this->line('Valid Until: '.date('Y-m-d H:i:s', $certData['validTo_time_t']));
        $this->line('Serial Number: '.$certData['serialNumber']);
        $this->line('Signature Algorithm: '.$certData['signatureTypeSN']);
    }

    protected function testCertificate()
    {
        $this->newLine();
        $this->info('🧪 Testing certificate...');

        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        if (! file_exists($certPath) || ! file_exists($keyPath)) {
            $this->error('Certificate or key file not found');

            return;
        }

        // Test certificate validity
        $cert = openssl_x509_read(file_get_contents($certPath));
        $key = openssl_pkey_get_private(file_get_contents($keyPath));

        if ($cert && $key) {
            $this->line('✅ Certificate format is valid');
            $this->line('✅ Private key format is valid');

            // Test signature
            $testData = 'test_qz_tray_'.time();
            $signature = '';
            if (openssl_sign($testData, $signature, $key, OPENSSL_ALGO_SHA512)) {
                $this->line('✅ Signing with SHA512 works');
            }

            openssl_free_key($key);
            openssl_x509_free($cert);
        } else {
            $this->error('❌ Certificate or key is invalid');
        }
    }
}
