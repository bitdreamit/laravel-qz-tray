<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;

class GenerateCertificate extends Command
{
    protected $signature = 'qz:generate-certificate
                            {--force : Force generation even if certificate exists}
                            {--show  : Show certificate details after generation}';

    protected $description = 'Generate SSL certificate for QZ Tray';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled. Please enable it in your PHP configuration.');
            return 1;
        }

        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath  = config('qz-tray.key_path',  storage_path('qz/private-key.pem'));

        if (file_exists($certPath) && file_exists($keyPath) && ! $this->option('force')) {
            $this->info('✅ Certificate already exists. Use --force to regenerate.');
            $this->line('  Certificate: '.$certPath);
            $this->line('  Private key: '.$keyPath);

            if ($this->option('show')) {
                $this->showCertificateDetails($certPath);
            }

            return 0;
        }

        $this->info('🔐 Generating QZ Tray certificate...');

        $certConfig = config('qz-tray.certificate', []);

        $opensslConfig = [
            'digest_alg'       => $certConfig['algorithm'] ?? 'sha256',
            'private_key_bits' => $certConfig['key_bits']  ?? 2048,
            'private_key_type' => $certConfig['key_type']  ?? OPENSSL_KEYTYPE_RSA,
        ];

        $this->line('  Generating private key...');
        $privateKey = openssl_pkey_new($opensslConfig);
        if (! $privateKey) {
            // `openssl_error_string` returns false when there is no error, so
            // we need to guard against that before string concatenation.
            $err = openssl_error_string() ?: 'unknown error';
            $this->error('❌ Failed to generate private key: ' . $err);
            return 1;
        }

        openssl_pkey_export($privateKey, $privateKeyPEM);

        $subject = $certConfig['subject'] ?? [
            'countryName'            => 'US',
            'stateOrProvinceName'    => 'NY',
            'localityName'           => 'Canastota',
            'organizationName'       => 'QZ Industries, LLC',
            'organizationalUnitName' => 'QZ Industries, LLC',
            'commonName'             => 'QZ Tray Demo Cert',
            'emailAddress'           => 'support@qz.io',
        ];

        $this->line('  Creating certificate signing request...');
        $csr = openssl_csr_new($subject, $privateKey, $opensslConfig);
        if (! $csr) {
            $err = openssl_error_string() ?: 'unknown error';
            $this->error('❌ Failed to create CSR: ' . $err);
            return 1;
        }

        $validityDays = $certConfig['validity_days'] ?? 7300;
        $cert         = openssl_csr_sign($csr, null, $privateKey, $validityDays, $opensslConfig, time());
        if (! $cert) {
            $err = openssl_error_string() ?: 'unknown error';
            $this->error('❌ Failed to sign certificate: ' . $err);
            return 1;
        }

        openssl_x509_export($cert, $certificatePEM);

        // Create directory if needed
        $certDir = dirname($certPath);
        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
            $this->line('  Created directory: '.$certDir);
        }

        file_put_contents($certPath, $certificatePEM);
        file_put_contents($keyPath,  $privateKeyPEM);
        chmod($certPath, 0644);
        chmod($keyPath,  0600);

        $this->info('✅ Certificate generated successfully!');
        $this->line('  📄 Certificate: '.$certPath);
        $this->line('  🔑 Private key: '.$keyPath);
        $this->line('  ⏳ Validity: '.$validityDays.' days ('.round($validityDays / 365).' years)');

        if ($this->option('show')) {
            $this->showCertificateDetails($certPath);
        }

        $this->testCertificate($certPath, $keyPath);

        return 0;
    }

    protected function showCertificateDetails(string $certPath): void
    {
        $certData = openssl_x509_parse(file_get_contents($certPath));
        if (! $certData) {
            $this->warn('Could not parse certificate details.');
            return;
        }

        $this->newLine();
        $this->info('📋 Certificate Details:');
        $this->line('  Subject:    '.$certData['name']);
        $this->line('  Valid From: '.date('Y-m-d H:i:s', $certData['validFrom_time_t']));
        $this->line('  Valid Until: '.date('Y-m-d H:i:s', $certData['validTo_time_t']));
        $this->line('  Serial:     '.$certData['serialNumber']);
        $this->line('  Algorithm:  '.$certData['signatureTypeSN']);
    }

    protected function testCertificate(string $certPath, string $keyPath): void
    {
        $this->newLine();
        $this->info('🧪 Testing certificate...');

        $cert = openssl_x509_read(file_get_contents($certPath));
        $key  = openssl_pkey_get_private(file_get_contents($keyPath));

        if (! $cert || ! $key) {
            $this->error('❌ Certificate or key is invalid.');
            return;
        }

        $this->line('  ✅ Certificate format valid');
        $this->line('  ✅ Private key format valid');

        $testData  = 'test_qz_tray_'.time();
        $signature = '';
        if (openssl_sign($testData, $signature, $key, OPENSSL_ALGO_SHA512)) {
            $this->line('  ✅ SHA512 signing works');
        } else {
            $err = openssl_error_string() ?: 'unknown error';
            $this->warn('  ⚠️  SHA512 signing failed: ' . $err);
        }

        // openssl_free_key / openssl_x509_free are no-ops / deprecated in PHP 8+
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($key);
            openssl_x509_free($cert);
        }
    }
}
