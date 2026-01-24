<?php

namespace Bitdreamit\QzTray;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class QzTrayServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Load Package Views
        |--------------------------------------------------------------------------
        */
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'qz-tray');

        /*
        |--------------------------------------------------------------------------
        | Publish Views (Allow Application Override)
        |--------------------------------------------------------------------------
        */
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/qz-tray'),
        ], 'qz-blade');

        /*
        |--------------------------------------------------------------------------
        | Publish Configuration
        |--------------------------------------------------------------------------
        */
        $this->publishes([
            __DIR__.'/../config/qz-tray.php' => config_path('qz-tray.php'),
        ], 'qz-config');

        /*
        |--------------------------------------------------------------------------
        | Publish Database Migrations
        |--------------------------------------------------------------------------
        */
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'qz-migrations');

        /*
        |--------------------------------------------------------------------------
        | Publish Frontend Assets
        |--------------------------------------------------------------------------
        */
        $this->publishes([
            __DIR__.'/../resources/js' => public_path('vendor/qz-tray/js'),
            __DIR__.'/../resources/css' => public_path('vendor/qz-tray/css'),
            __DIR__.'/../resources/fonts' => public_path('vendor/qz-tray/fonts'),
            __DIR__.'/../resources/assets' => public_path('vendor/qz-tray/assets'),
        ], 'qz-assets');
        
        /*
        |--------------------------------------------------------------------------
        | Publish Certificate Storage Directory
        |--------------------------------------------------------------------------
        */
        $this->publishes([
            __DIR__.'/../storage/qz' => storage_path('qz'),
        ], 'qz-certificate');

        /*
        |--------------------------------------------------------------------------
        | Publish Desktop Installers
        |--------------------------------------------------------------------------
        */
        $this->publishes([
            __DIR__.'/../resources/installers' => public_path('vendor/qz-tray/installers'),
        ], 'qz-installers');

        /*
        |--------------------------------------------------------------------------
        | Load Routes
        |--------------------------------------------------------------------------
        */
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        /*
        |--------------------------------------------------------------------------
        | Optional Certificate Auto-Generation
        |--------------------------------------------------------------------------
        | Disabled by default for production safety
        */
        if (config('qz-tray.auto_generate_cert', false)) {
            $this->autoGenerateCertificate();
        }

        /*
        |--------------------------------------------------------------------------
        | Register Artisan Commands
        |--------------------------------------------------------------------------
        */
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\InstallQzTray::class,
                Console\Commands\GenerateCertificate::class,
                Console\Commands\ClearQzCache::class,
            ]);
        }
    }

    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/qz-tray.php',
            'qz-tray'
        );
    }

    /**
     * Auto-generate QZ Tray SSL certificate.
     * This feature is optional and disabled by default.
     */
    protected function autoGenerateCertificate(): void
    {
        if (! extension_loaded('openssl')) {
            Log::warning('[QZ Tray] OpenSSL extension not available.');

            return;
        }

        $certPath = config('qz-tray.cert_path');
        $keyPath = config('qz-tray.key_path');

        if (! $certPath || ! $keyPath) {
            Log::warning('[QZ Tray] Certificate paths are not configured.');

            return;
        }

        if (file_exists($certPath) && file_exists($keyPath)) {
            return;
        }

        $directory = dirname($certPath);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $certificateConfig = config('qz-tray.certificate', []);

        $keyConfig = [
            'digest_alg' => $certificateConfig['algorithm'] ?? 'sha256',
            'private_key_bits' => $certificateConfig['key_bits'] ?? 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($keyConfig);
        if (! $privateKey) {
            Log::error('[QZ Tray] Failed to generate private key.');

            return;
        }

        openssl_pkey_export($privateKey, $privateKeyPem);

        $subject = $certificateConfig['subject'] ?? [
            'countryName' => 'US',
            'organizationName' => 'QZ Tray',
            'organizationalUnitName' => 'QZ Tray',
            'commonName' => 'QZ Tray Certificate',
        ];

        $csr = openssl_csr_new($subject, $privateKey, $keyConfig);
        if (! $csr) {
            Log::error('[QZ Tray] Failed to generate CSR.');

            return;
        }

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            $certificateConfig['validity_days'] ?? 3650,
            $keyConfig
        );

        if (! $certificate) {
            Log::error('[QZ Tray] Failed to sign certificate.');

            return;
        }

        openssl_x509_export($certificate, $certificatePem);

        file_put_contents($certPath, $certificatePem);
        file_put_contents($keyPath, $privateKeyPem);

        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        openssl_free_key($privateKey);

        Log::info('[QZ Tray] SSL certificate generated successfully.');
    }
}
