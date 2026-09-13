<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Bitdreamit\QzTray\Support\CertKit;
use Bitdreamit\QzTray\Support\ZipBuilder;
use Illuminate\Console\Command;

/**
 * qz:client-bundle — build the one-shot Windows client trust bundle.
 *
 * Output: storage/qz/client-bundle/ containing
 *   override.crt            the root your clients trust (qz:override:export)
 *   qz-client-setup.ps1     admin PowerShell script — deploys ALL trust silently:
 *                             1. installs QZ Tray's localhost transport root
 *                                (C:\ProgramData\qz\root-ca.crt) into the
 *                                Windows "Trusted Root Certification
 *                                Authorities" store  → no browser TLS warning
 *                             2. writes override.crt into %PROGRAMFILES%\QZ Tray
 *                                → no signing "Untrusted website" prompt
 *                             3. optionally whitelists the site certificate
 *                                into allowed.dat (`qz-tray-console.exe --allow`)
 *                             4. optionally adds the Chrome/Edge
 *                                LocalNetworkAccess policy (Chrome 138+ LNA prompt)
 *                             5. restarts QZ Tray
 *   setup.bat               double-click launcher (bypasses ExecutionPolicy)
 *   provision.json          QZ Tray 2.2.4+ provisioning sideload (type ca/cert/policy)
 *   digital-certificate.txt copy of the site certificate for --allow / Site Manager
 *   README.txt              what to hand to whom
 *
 * The bundle is also downloadable at GET /qz/client-bundle (config-gated).
 */
class ClientBundle extends Command
{
    protected $signature = 'qz:client-bundle
                            {--force : Rebuild even if the bundle directory is already populated}
                            {--lna-domains= : Comma-separated domains for the Chrome/Edge LocalNetworkAccess policy (default: config qz-tray.client_bundle.lna_domains)}
                            {--no-lna : Skip the Chrome/Edge Local Network Access policy step}
                            {--no-allow : Skip the allowed.dat whitelisting step}
                            {--zip : Also produce a ready-to-distribute .zip (pure-PHP fallback when ext-zip is missing)}';

    protected $description = 'Build the Windows client trust bundle (override.crt + PowerShell setup script + provision.json) for zero-prompt QZ Tray';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled.');
            return 1;
        }

        $certPath   = (string) (config('qz-tray.cert_path') ?: storage_path('qz/digital-certificate.txt'));
        $caCertPath = (string) (config('qz-tray.certificate.ca.cert_path') ?: storage_path('qz/ca/qz-root-ca.crt'));
        $bundleDir  = storage_path('qz/client-bundle');

        if (! is_file($certPath)) {
            $this->error('❌ Site certificate not found. Run: php artisan qz:generate-certificate');
            return 1;
        }

        if (is_dir($bundleDir) && ! $this->option('force') && is_file($bundleDir.'/qz-client-setup.ps1')) {
            $this->info('✅ Bundle already exists at '.$bundleDir.' (use --force to rebuild).');
            $this->line('  Serving it at: GET '.url('/'.config('qz-tray.routes.prefix', 'qz').'/client-bundle'));

            return 0;
        }

        if (! is_dir($bundleDir) && ! mkdir($bundleDir, 0755, true) && ! is_dir($bundleDir)) {
            $this->error('❌ Unable to create bundle directory: '.$bundleDir);
            return 1;
        }

        $leafPem = (string) file_get_contents($certPath);

        // 1. override.crt (root CA when present + chained, else the leaf)
        $overridePem = $leafPem;
        $overrideSource = 'site-certificate (self-signed)';
        if (is_file($caCertPath)) {
            $caPem = (string) file_get_contents($caCertPath);
            $overridePem = $caPem;
            $overrideSource = CertKit::leafChainsTo($leafPem, $caPem)
                ? 'local Root CA (leaf chains to it)'
                : 'local Root CA (leaf not yet signed by it — run qz:generate-certificate --force --ca)';
        }

        CertKit::writeAtomic($bundleDir.'/override.crt', $overridePem, 0644);

        // 2. digital-certificate.txt (the exact cert QZ Tray downloads from /qz/certificate)
        CertKit::writeAtomic($bundleDir.'/digital-certificate.txt', $leafPem, 0644);

        // 3. LNA domains
        $lnaDomains = $this->option('lna-domains') !== null && $this->option('lna-domains') !== ''
            ? array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string) $this->option('lna-domains')) ?: [])))
            : array_values((array) config('qz-tray.client_bundle.lna_domains', []));
        $includeLna = ! $this->option('no-lna') && $lnaDomains !== [];

        $includeAllow = ! $this->option('no-allow');

        // 4. PowerShell setup script from the stub
        $stubPath = __DIR__.'/../../../resources/stubs/qz-client-setup.ps1';
        if (! is_file($stubPath)) {
            $this->error('❌ Missing stub: resources/stubs/qz-client-setup.ps1');
            return 1;
        }

        $ps1 = (string) file_get_contents($stubPath);
        $ps1 = str_replace(
            [
                '@@OVERRIDE_CERT_B64@@',
                '@@SITE_CERT_B64@@',
                '@@INCLUDE_ALLOW_STEP@@',
                '@@INCLUDE_LNA_STEP@@',
                '@@LNA_DOMAINS_JSON@@',
                '@@RESTART_TRAY@@',
                '@@GENERATED_AT@@',
                '@@PACKAGE_VERSION@@',
            ],
            [
                base64_encode($overridePem),
                base64_encode($leafPem),
                $includeAllow ? '1' : '0',
                $includeLna ? '1' : '0',
                json_encode(array_values($lnaDomains)),
                config('qz-tray.client_bundle.restart_tray', true) ? '1' : '0',
                now()->toIso8601String(),
                \Bitdreamit\QzTray\QzTrayServiceProvider::VERSION,
            ],
            $ps1
        );

        CertKit::writeAtomic($bundleDir.'/qz-client-setup.ps1', $ps1, 0644);

        // 5. setup.bat launcher
        $bat = "@echo off\r\n"
            ."REM QZ Tray client trust setup - launcher (run on the PRINT CLIENT PC, as Administrator)\r\n"
            ."REM Generated by bitdreamit/laravel-qz-tray v".\Bitdreamit\QzTray\QzTrayServiceProvider::VERSION."\r\n"
            ."net session >nul 2>&1\r\n"
            ."if %errorlevel% neq 0 (\r\n"
            ."    echo This script must run as Administrator. Right-click setup.bat ^> Run as administrator.\r\n"
            ."    pause\r\n"
            ."    exit /b 1\r\n"
            .")\r\n"
            ."powershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0qz-client-setup.ps1\"\r\n"
            ."pause\r\n";
        CertKit::writeAtomic($bundleDir.'/setup.bat', $bat, 0644);

        // 6. provision.json (QZ Tray 2.2.4+ sideload)
        $provision = [];
        $provision[] = [
            'description' => 'Trust our signing root (removes the Untrusted Website prompt)',
            'type'        => 'ca',
            'phase'       => 'certgen',
            'data'        => 'override.crt',
        ];

        if ($includeAllow) {
            $provision[] = [
                'description' => 'Whitelist the site certificate in allowed.dat (no Remember click)',
                'type'        => 'cert',
                'phase'       => 'startup',
                'data'        => 'digital-certificate.txt',
            ];
        }

        if ($includeLna) {
            $provision[] = [
                'description' => 'Chrome/Edge: auto-allow local network access for our tenant domains',
                'type'        => 'policy',
                'app'         => 'chromium',
                'name'        => 'LocalNetworkAccessAllowedForUrls',
                'format'      => 'array',
                'phase'       => 'certgen',
                'data'        => json_encode(array_values($lnaDomains)),
            ];
            $provision[] = [
                'description' => 'Firefox: auto-allow local network access for our tenant domains',
                'type'        => 'policy',
                'app'         => 'firefox',
                'name'        => 'LocalNetworkAccess',
                'format'      => 'map',
                'phase'       => 'certgen',
                'data'        => json_encode(['SkipDomains' => array_values($lnaDomains)]),
            ];
        }

        CertKit::writeAtomic(
            $bundleDir.'/provision.json',
            json_encode($provision, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
            0644
        );

        // 7. README.txt
        $fp = CertKit::fingerprintSha1Pretty($overridePem) ?? 'n/a';
        $readme = "QZ Tray Client Trust Bundle\n"
            ."Generated: ".now()->toDateTimeString()."\n"
            ."Package:   bitdreamit/laravel-qz-tray v".\Bitdreamit\QzTray\QzTrayServiceProvider::VERSION."\n"
            ."Trust root fingerprint (SHA-1): {$fp}\n"
            .str_repeat('=', 72)."\n\n"
            ."WHAT THIS BUNDLE DOES (all free, no paid QZ certificate needed)\n"
            ."1. Installs QZ Tray's own localhost TLS root into the Windows trust\n"
            ."   store so https/wss to localhost:8181 shows NO browser warning.\n"
            ."2. Deploys override.crt into the QZ Tray install folder so the site\n"
            ."   certificate is trusted SILENTLY - no Untrusted Website dialog.\n"
            ."3. Optionally whitelists the site certificate in allowed.dat.\n"
            ."4. Optionally adds the Chrome/Edge LocalNetworkAccess policy so the\n"
            ."   'allow local network access' prompt never appears either.\n\n"
            ."HOW TO USE\n"
            ."Per machine : copy this folder to the client PC, right-click setup.bat\n"
            ."              > Run as administrator.\n"
            ."Fleet/GPO   : distribute qz-client-setup.ps1 via GPO startup script or\n"
            ."              Intune/MDM. Run once per machine, re-run any time (idempotent).\n"
            ."QZ Tray 2.2.4+ provisioning: copy provision.json + override.crt (+\n"
            ."              digital-certificate.txt) into C:\Program Files\QZ Tray\provision\\n"
            ."              then re-run certgen: qz-tray-console.exe certgen\n\n"
            ."FILES\n"
            ."override.crt              trust root for QZ Tray (signing layer)\n"
            ."digital-certificate.txt   the site certificate (for --allow / Site Manager)\n"
            ."qz-client-setup.ps1       main deployment script\n"
            ."setup.bat                 admin launcher for the ps1\n"
            ."provision.json            QZ Tray provisioning sideload definition\n"
            ."qz-client-bundle.zip      (when built with --zip) everything above zipped\n";
        CertKit::writeAtomic($bundleDir.'/README.txt', $readme, 0644);

        // 8. Optional zip. v1.4.2: ZipArchive's close() — the step that
        // actually WRITES the archive — used to go unchecked, so a quota or
        // open_basedir failure silently left a corrupt archive on disk that
        // clients downloaded as an "invalid zip". We now verify close(),
        // delete any partial result, and fall back to a spec-compliant
        // pure-PHP writer that needs no extensions at all (common on cPanel
        // builds where php-zip is missing).
        $zipPath = null;
        if ($this->option('zip')) {
            $zipPath = $bundleDir.'/qz-client-bundle.zip';

            $zipFiles = [
                $bundleDir.'/override.crt'            => 'override.crt',
                $bundleDir.'/digital-certificate.txt' => 'digital-certificate.txt',
                $bundleDir.'/qz-client-setup.ps1'     => 'qz-client-setup.ps1',
                $bundleDir.'/setup.bat'               => 'setup.bat',
                $bundleDir.'/provision.json'          => 'provision.json',
                $bundleDir.'/README.txt'              => 'README.txt',
            ];

            $built  = false;
            $reason = '';

            if (extension_loaded('zip')) {
                $zip = new \ZipArchive();

                if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                    foreach ($zipFiles as $src => $name) {
                        if (is_file($src)) {
                            $zip->addFile($src, $name);
                        }
                    }

                    $built = $zip->close() === true && is_file($zipPath) && filesize($zipPath) > 0;

                    if (! $built) {
                        $reason = 'ZipArchive failed to finalise the archive (quota / open_basedir?)';
                    }
                } else {
                    $reason = 'ZipArchive could not open the target file';
                }

                if (! $built) {
                    @unlink($zipPath); // never leave a partial archive behind
                }
            } else {
                $reason = 'ext-zip not loaded';
            }

            if (! $built) {
                $fbError = null;
                $built   = ZipBuilder::build($zipFiles, $zipPath, $fbError);

                if (! $built) {
                    $reason = trim($reason.'; pure-PHP fallback: '.($fbError ?? 'unknown error'), '; ');
                }
            }

            if ($built) {
                $sha = hash_file('sha256', $zipPath) ?: 'n/a';
                $this->line('  📦 Zip: '.$zipPath);
                $this->line('     Size: '.number_format((float) filesize($zipPath) / 1024, 1).' KB | sha256: '.$sha);
                $this->line('     Verify after download (Windows): certutil -hashfile qz-client-bundle.zip SHA256');
            } else {
                $this->warn('⚠️  Zip could not be created ('.$reason.').');
                $this->line('   The bundle files themselves are fine in '.$bundleDir.' — distribute the folder directly or zip it up manually.');
                $zipPath = null;
            }
        }

        // Stamp with the cert fingerprint so the /qz/client-bundle endpoint
        // knows when the bundle is stale and rebuilds itself automatically.
        // v1.4.2: written AFTER the (optional) zip so a failed zip build can
        // never leave a fresh stamp in front of a stale or corrupt archive.
        CertKit::writeAtomic($bundleDir.'/.built-stamp', md5_file($certPath), 0644);

        $this->newLine();
        $this->info('✅ Client bundle built: '.$bundleDir);
        $this->line('  Trust root: '.$overrideSource);
        $this->line('  Fingerprint (SHA-1): '.$fp);
        $this->line('  allowed.dat step: '.($includeAllow ? 'included' : 'skipped'));
        $this->line('  LNA policy step: '.($includeLna ? 'included ('.implode(', ', $lnaDomains).')' : 'skipped'));
        $this->newLine();
        $this->info('Deploy: run setup.bat as admin on each client, or distribute via GPO/Intune.');
        $this->line('Download endpoint: GET '.url('/'.config('qz-tray.routes.prefix', 'qz').'/client-bundle'));

        return 0;
    }
}
