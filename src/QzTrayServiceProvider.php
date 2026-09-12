<?php

namespace Bitdreamit\QzTray;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class QzTrayServiceProvider extends ServiceProvider
{
    /**
     * Package version. Kept in one place so /qz/status and the changelog
     * agree with composer.json (the previous hardcoded '1.0.0' in the
     * controller drifted from the real version). Bump on every release.
     */
    public const VERSION = '1.4.0';

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'qz-tray');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/qz-tray'),
        ], 'qz-blade');

        $this->publishes([
            __DIR__.'/../config/qz-tray.php' => config_path('qz-tray.php'),
        ], 'qz-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'qz-migrations');

        // SECURITY (AUDIT C1): the old code published the ENTIRE resources/assets
        // directory — including resources/assets/signing/sign-message.php — into
        // the PUBLIC web root. Once published and visited over HTTP, that sample
        // script signs ANY ?request= value with the server's private key and
        // requires no authentication, silently bypassing the CSRF-protected
        // /qz/sign endpoint. We now publish assets file-by-file, skipping every
        // PHP file and the whole signing/ sample folder. The signing samples
        // remain viewable in the vendor/ source tree for reference.
        $this->publishes($this->publishableAssets(), 'qz-assets');

        $this->publishes([
            __DIR__.'/../resources/installers' => public_path('vendor/qz-tray/installers'),
        ], 'qz-installers');

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Optionally load API routes when the host app wants a stateless,
        // sanctum-protected surface. Enabled via config('qz-tray.routes.api.enabled').
        if (config('qz-tray.routes.api.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if (config('qz-tray.auto_generate_cert', false)) {
            $this->autoGenerateCertificate();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\InstallQzTray::class,
                Console\Commands\GenerateCertificate::class,
                Console\Commands\GenerateCa::class,
                Console\Commands\ClearQzCache::class,
                Console\Commands\PrunePreferences::class,
                Console\Commands\PruneJobs::class,
                Console\Commands\QzDoctor::class,
                Console\Commands\ExportCertificate::class,
                Console\Commands\ImportCertificate::class,
                Console\Commands\ExportOverride::class,
                Console\Commands\ClientBundle::class,
                Console\Commands\WatchCertificate::class,
            ]);

            // v1.4.0: auto-schedule the certificate watcher when an external
            // source is configured, so Let's Encrypt renewals re-import
            // without anyone remembering to run artisan by hand.
            if (config('qz-tray.certificate.watch.source_cert')) {
                $this->app->afterResolving('schedule', function ($schedule) {
                    $schedule->command('qz:watch-certificate')
                        ->dailyAt((string) config('qz-tray.certificate.watch.schedule_time', '03:17'))
                        ->withoutOverlapping()
                        ->runInBackground();
                });
            }
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/qz-tray.php',
            'qz-tray'
        );
    }

    /**
     * Build the asset publish map, excluding anything executable.
     *
     * Rules:
     *  - the signing/ sample folder is never published (it contains working
     *    sign-message.* scripts whose PHP variant is a live signing endpoint);
     *  - any stray *.php under assets is never published;
     *  - empty placeholder installer files are irrelevant here but the
     *    installer() endpoint independently rejects 0-byte files.
     */
    protected function publishableAssets(): array
    {
        $map = [
            __DIR__.'/../resources/js'     => public_path('vendor/qz-tray/js'),
            __DIR__.'/../resources/css'    => public_path('vendor/qz-tray/css'),
            __DIR__.'/../resources/fonts'  => public_path('vendor/qz-tray/fonts'),
        ];

        $assetsDir = __DIR__.'/../resources/assets';
        $assetsOut = public_path('vendor/qz-tray/assets');

        if (! is_dir($assetsDir)) {
            return $map;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetsDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            // Never publish executable/PHP content to the web root.
            if (strtolower($file->getExtension()) === 'php') {
                continue;
            }

            $relative = str_replace($assetsDir.DIRECTORY_SEPARATOR, '', $file->getPathname());

            // The signing/ samples teach how to build signers for other stacks;
            // they must never live under public/.
            if (str_starts_with($relative, 'signing'.DIRECTORY_SEPARATOR)
                || str_starts_with($relative, 'signing/')) {
                continue;
            }

            $map[$file->getPathname()] = $assetsOut.DIRECTORY_SEPARATOR.$relative;
        }

        return $map;
    }

    protected function autoGenerateCertificate(): void
    {
        if (! extension_loaded('openssl')) {
            Log::warning('[QZ Tray] OpenSSL extension not available.');
            return;
        }

        $certPath = config('qz-tray.cert_path');
        $keyPath  = config('qz-tray.key_path');

        if (! $certPath || ! $keyPath) {
            Log::warning('[QZ Tray] Certificate paths are not configured.');
            return;
        }

        if (file_exists($certPath) && file_exists($keyPath)) {
            return;
        }

        // AUDIT C5: this method runs inside boot() — i.e. on every request
        // while the certificate is missing. Two concurrent FPM workers both
        // seeing "missing" used to generate DIFFERENT key pairs and
        // interleave file_put_contents calls, leaving cert/key mismatched on
        // disk and every signature failing until manual regeneration. An
        // atomic cache lock serializes generation; if the cache store cannot
        // hold locks we degrade to temp-file + rename, which is atomic on
        // POSIX filesystems and still avoids partial/mixed writes.
        $lock = null;
        $lockAcquired = false;

        try {
            if (method_exists(Cache::class, 'lock') && config('cache.default') !== 'none') {
                $lock = Cache::lock('qz-tray:cert-generation', 30);
                $lockAcquired = $lock->get();
            } else {
                $lockAcquired = true;
            }
        } catch (\Throwable $e) {
            $lockAcquired = true; // no usable lock store — proceed with atomic writes only
        }

        if (! $lockAcquired) {
            Log::info('[QZ Tray] Certificate generation already in progress by another worker.');
            return;
        }

        try {
            // Re-check after acquiring the lock — the losing worker may have
            // finished generation while we were waiting for the lock.
            if (file_exists($certPath) && file_exists($keyPath)) {
                return;
            }

            $this->generateCertificateFiles($certPath, $keyPath);

            Log::info('[QZ Tray] SSL certificate generated successfully.');
        } catch (\Throwable $e) {
            // AUDIT C5 (second half): generation must never take the whole
            // application down. Read-only storage (Docker images, Lambda
            // snapshots, immutable deploys) previously caused uncaught
            // warnings/exceptions on every boot. Fail loudly once, in the
            // log, and let the app serve requests — /qz/sign will return a
            // clear 500 until the certificate problem is fixed.
            Log::error('[QZ Tray] Automatic certificate generation failed: '.$e->getMessage());
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable $e) {
                // lock store unavailable mid-flight — nothing to release
            }
        }
    }

    /**
     * Generate the self-signed certificate + key and move them into place
     * atomically: both PEMs are written to temporary files first and only
     * then renamed. rename(2) on the same filesystem is atomic, so a request
     * that reads the paths mid-generation sees either the old file or the
     * complete new file — never a truncated or mismatched pair.
     */
    protected function generateCertificateFiles(string $certPath, string $keyPath): void
    {
        $directory = dirname($certPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create certificate directory: {$directory}");
        }

        $certificateConfig = config('qz-tray.certificate', []);

        $keyConfig = [
            'digest_alg'       => $certificateConfig['algorithm'] ?? 'sha256',
            'private_key_bits' => (int) ($certificateConfig['key_bits'] ?? 2048),
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($keyConfig);
        if (! $privateKey) {
            throw new \RuntimeException('Failed to generate private key: '.(openssl_error_string() ?: 'unknown error'));
        }

        if (! openssl_pkey_export($privateKey, $privateKeyPem)) {
            throw new \RuntimeException('Failed to export private key: '.(openssl_error_string() ?: 'unknown error'));
        }

        $subject = $certificateConfig['subject'] ?? [
            'countryName'      => 'US',
            'organizationName' => 'QZ Tray',
            'commonName'       => 'QZ Tray Certificate',
        ];

        $csr = openssl_csr_new($subject, $privateKey, $keyConfig);
        if (! $csr) {
            throw new \RuntimeException('Failed to generate CSR: '.(openssl_error_string() ?: 'unknown error'));
        }

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            (int) ($certificateConfig['validity_days'] ?? 7300),
            $keyConfig,
            time()
        );

        if (! $certificate) {
            throw new \RuntimeException('Failed to sign certificate: '.(openssl_error_string() ?: 'unknown error'));
        }

        openssl_x509_export($certificate, $certificatePem);

        // Atomic two-step write: temp file in the SAME directory (rename
        // across filesystems is not atomic), then rename into place.
        $certTmp = tempnam($directory, 'qzcert');
        $keyTmp  = tempnam($directory, 'qzkey');

        if ($certTmp === false || $keyTmp === false) {
            throw new \RuntimeException('Unable to create temporary files for certificate generation.');
        }

        try {
            if (file_put_contents($certTmp, $certificatePem) === false
                || file_put_contents($keyTmp, $privateKeyPem) === false) {
                throw new \RuntimeException('Unable to write certificate temp files (read-only storage?).');
            }

            chmod($certTmp, 0644);
            chmod($keyTmp, 0600);

            if (! rename($certTmp, $certPath)) {
                throw new \RuntimeException("Unable to move certificate into place at {$certPath}");
            }

            if (! rename($keyTmp, $keyPath)) {
                throw new \RuntimeException("Unable to move private key into place at {$keyPath}");
            }
        } catch (\Throwable $e) {
            @unlink($certTmp);
            @unlink($keyTmp);

            throw $e;
        }

        // openssl_free_key() is a no-op in PHP 8+ — skip to avoid deprecation notices
        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privateKey);
        }
    }
}
