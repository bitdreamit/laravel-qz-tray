<?php

namespace Bitdreamit\QzTray;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class QzTrayServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/qz-tray.php' => config_path('qz-tray.php'),
        ], 'qz-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'qz-migrations');

        // Publish JS assets
        $this->publishes([
            __DIR__.'/../resources/js' => public_path('vendor/qz-tray'),
        ], 'qz-assets');

        // Publish certificate directory
        $this->publishes([
            __DIR__.'/../storage/qz' => storage_path('qz'),
        ], 'qz-certificate');

        // Publish installers
        $this->publishes([
            __DIR__.'/../resources/installers' => public_path('vendor/qz-tray/installers'),
        ], 'qz-installers');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Auto-generate certificate on first run
        $this->autoGenerateCertificate();

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\InstallQzTray::class,
                Console\Commands\GenerateCertificate::class,
                Console\Commands\ClearQzCache::class,
            ]);
        }
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/qz-tray.php',
            'qz-tray'
        );
    }

    /**
     * Auto-generate SSL certificate in demo style
     */
    protected function autoGenerateCertificate(): void
    {
        if (! extension_loaded('openssl')) {
            Log::warning('OpenSSL extension not loaded. QZ Tray certificate generation skipped.');

            return;
        }

        // Get paths from config
        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        // Skip if already exists
        if (file_exists($certPath) && file_exists($keyPath)) {
            return;
        }

        // Create directory
        $certDir = dirname($certPath);
        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }

        // Get certificate config
        $certConfig = config('qz-tray.certificate', []);

        // Generate in demo style
        $config = [
            'digest_alg' => $certConfig['algorithm'] ?? 'sha256',
            'private_key_bits' => $certConfig['key_bits'] ?? 2048,
            'private_key_type' => $certConfig['key_type'] ?? OPENSSL_KEYTYPE_RSA,
        ];

        // Generate key pair
        $privateKey = openssl_pkey_new($config);
        if (! $privateKey) {
            Log::error('Failed to generate private key for QZ Tray: '.openssl_error_string());

            return;
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

        // Create CSR
        $csr = openssl_csr_new($subject, $privateKey, $config);
        if (! $csr) {
            Log::error('Failed to generate CSR: '.openssl_error_string());
            openssl_free_key($privateKey);

            return;
        }

        // Generate self-signed certificate
        $validityDays = $certConfig['validity_days'] ?? 7300;
        $cert = openssl_csr_sign($csr, null, $privateKey, $validityDays, $config, time());
        if (! $cert) {
            Log::error('Failed to sign certificate: '.openssl_error_string());
            openssl_free_key($privateKey);

            return;
        }

        // Export certificate
        openssl_x509_export($cert, $certificatePEM);

        // Save files with exact demo format
        file_put_contents($certPath, $certificatePEM);
        file_put_contents($keyPath, $privateKeyPEM);

        // Set permissions
        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        Log::info('QZ Tray certificate generated automatically in demo format');

        // Clean up
        openssl_free_key($privateKey);
    }
}
