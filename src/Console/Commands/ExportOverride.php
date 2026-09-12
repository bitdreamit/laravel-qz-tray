<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Bitdreamit\QzTray\Support\CertKit;
use Illuminate\Console\Command;

/**
 * qz:override:export — produce the file clients deploy as QZ Tray's
 * override.crt so the "Untrusted website" prompt disappears completely.
 *
 * QZ Tray (2.1+) trusts any signing certificate that chains to the root
 * placed at %PROGRAMFILES%\QZ Tray\override.crt (or authcert.override= in
 * qz-tray.properties). This command decides WHAT that root should be:
 *
 *   - If a local Root CA exists AND the current leaf chains to it  → the CA cert
 *     (best: CA can outlive leaf rotations, clients never re-trust).
 *   - Otherwise                                                     → the current
 *     self-signed leaf itself (same fingerprint everywhere still required).
 */
class ExportOverride extends Command
{
    protected $signature = 'qz:override:export
                            {--out= : Output path (default: storage/qz/client-bundle/override.crt)}
                            {--show  : Show what was exported and how to deploy it}';

    protected $description = 'Export the certificate clients install as QZ Tray override.crt (root CA when available, else the site certificate)';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled.');
            return 1;
        }

        $certPath   = (string) (config('qz-tray.cert_path') ?: storage_path('qz/digital-certificate.txt'));
        $caCertPath = (string) (config('qz-tray.certificate.ca.cert_path') ?: storage_path('qz/ca/qz-root-ca.crt'));

        if (! is_file($certPath)) {
            $this->error('❌ Site certificate not found. Run: php artisan qz:generate-certificate');
            return 1;
        }

        $leafPem = (string) file_get_contents($certPath);
        $source  = 'site-certificate';

        if (is_file($caCertPath)) {
            $caPem = (string) file_get_contents($caCertPath);

            if (CertKit::leafChainsTo($leafPem, $caPem)) {
                $source = 'root-ca';
            } else {
                $this->warn('⚠️  A Root CA exists but the current site certificate was NOT signed by it.');
                $this->line('   Regenerate the leaf with: php artisan qz:generate-certificate --force --ca');
                $this->line('   Exporting the CA anyway — it will cover leaf certificates signed AFTER deployment.');
            }

            $overridePem = $caPem;
            $source      = $source === 'root-ca' ? 'root-ca' : 'root-ca (leaf not yet chained)';
        } else {
            $overridePem = $leafPem;
        }

        $outPath = (string) ($this->option('out') ?: storage_path('qz/client-bundle/override.crt'));

        CertKit::writeAtomic($outPath, $overridePem, 0644);

        $this->info('✅ override.crt exported: '.$outPath);
        $this->line('  Source: '.$source);

        if ($this->option('show')) {
            $fp = CertKit::fingerprintSha1Pretty($overridePem);
            $this->newLine();
            $this->info('📋 Deploy to every Windows client (one of):');
            $this->line('  A) Bundle (recommended): php artisan qz:client-bundle  →  run qz-client-setup.ps1 as admin');
            $this->line('  B) Manual copy:');
            $this->line('     copy override.crt  →  C:\Program Files\QZ Tray\override.crt    (then restart QZ Tray)');
            $this->line('  C) qz-tray.properties (2.0.2+): authcert.override = C:\Program Files\QZ Tray\override.crt');
            $this->line('  D) Provisioning sideload (2.2.4+): provision/ folder, type "ca", data override.crt');
            if ($fp) {
                $this->newLine();
                $this->line('  Fingerprint (SHA-1): '.$fp);
                $this->line('  → This must match the value QZ Tray shows in its dialog / Site Manager.');
            }
        }

        return 0;
    }
}
