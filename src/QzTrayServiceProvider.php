<?php

namespace Bitdreamit\QzTray;

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

        // Publish certificate if not exists
        $this->publishes([
            __DIR__.'/../storage/qz' => storage_path('qz'),
        ], 'qz-certificate');

        // Publish installers
        $this->publishes([
            __DIR__.'/../resources/installers' => public_path('vendor/qz-tray/installers'),
        ], 'qz-installers');

        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Auto-generate certificate on first install
//        $this->autoGenerateCertificate();

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\InstallQzTray::class,
                Console\Commands\GenerateCertificate::class,
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
     * Auto-generate SSL certificate if it doesn't exist.
     */
    protected function autoGenerateCertificate(): void
    {
        if (! extension_loaded('openssl')) {
            return;
        }

        $certPath = config('qz-tray.cert_path', storage_path('qz/certificate.pem'));
        $keyPath  = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        if (file_exists($certPath) && file_exists($keyPath)) {
            return;
        }

        @mkdir(dirname($certPath), 0755, true);

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $keypair = openssl_pkey_new($config);

        if ($keypair === false) {
            throw new \RuntimeException(
                'Unable to generate OpenSSL private key.
            Please check your OpenSSL configuration.'
            );
        }

        openssl_pkey_export($keypair, $privateKey);

        $dn = [
            'countryName' => 'US',
            'organizationName' => 'Laravel QZ Tray',
            'commonName' => 'qz-tray.local',
        ];

        $csr = openssl_csr_new($dn, $keypair, $config);
        $cert = openssl_csr_sign($csr, null, $keypair, 3650);

        openssl_x509_export($cert, $certificate);

        file_put_contents($certPath, $certificate);
        file_put_contents($keyPath, $privateKey);
    }

}
