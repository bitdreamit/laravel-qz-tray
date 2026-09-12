<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Bitdreamit\QzTray\Support\CertKit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * qz:watch-certificate — keep the QZ signing certificate in sync with an
 * external certificate source (Let's Encrypt / cPanel AutoSSL / Cloudflare
 * Origin) and warn before expiry when no source is configured.
 *
 * WHY
 * A CA-signed signing certificate removes the QZ Tray prompt WITHOUT any
 * client-side deployment (QZ Tray silently trusts public roots). But LE
 * renews every 60-90 days — if /qz/certificate keeps serving the OLD leaf
 * after the web server switched, clients see a mismatched/expiring
 * certificate and the prompt can come back. This command closes that loop:
 *
 *   - --source: watch a certbot "live" path (or any PEM pair). When the
 *     source file changes, the pair is validated and copied atomically into
 *     cert_path/key_path (with backup). Zero downtime, no manual import.
 *   - Without a source it simply reports expiry and fails (>0) when inside
 *     the warning window, so cron/scheduler output gets your attention.
 *
 * Schedule it (the service provider wires it automatically when
 * qz-tray.certificate.watch.source_cert is set):
 *     $schedule->command('qz:watch-certificate')->dailyAt('03:17');
 */
class WatchCertificate extends Command
{
    protected $signature = 'qz:watch-certificate
                            {--source-cert= : Override watch source PEM (default: config qz-tray.certificate.watch.source_cert)}
                            {--source-key=  : Override watch source key PEM (default: config qz-tray.certificate.watch.source_key)}
                            {--force : Re-import even if the source hash is unchanged}';

    protected $description = 'Sync the QZ signing certificate with an external source (Let\'s Encrypt/AutoSSL) and warn before expiry';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled.');
            return 1;
        }

        $certPath = (string) (config('qz-tray.cert_path') ?: storage_path('qz/digital-certificate.txt'));
        $keyPath  = (string) (config('qz-tray.key_path')  ?: storage_path('qz/private-key.pem'));

        $sourceCert = (string) ($this->option('source-cert') ?: config('qz-tray.certificate.watch.source_cert', ''));
        $sourceKey  = (string) ($this->option('source-key')  ?: config('qz-tray.certificate.watch.source_key', ''));

        // ---------- Mode 1: external source watching ----------
        if ($sourceCert !== '') {
            if (! is_file($sourceCert)) {
                $this->error("❌ Watch source not found: {$sourceCert}");
                $this->line('   Check qz-tray.certificate.watch.source_cert (common certbot path: /etc/letsencrypt/live/<domain>/fullchain.pem)');

                return 1;
            }

            $sourceKey = $sourceKey !== '' ? $sourceKey : $sourceCert; // fullchain files sometimes ship combined

            $hash = hash('sha256', $sourceCert.'|'.filemtime($sourceCert).'|'.filesize($sourceCert).'|'.md5_file($sourceCert));

            /** @var string|false $known */
            $known = Cache::get('qz-tray:cert-watch:hash');

            if ($hash === $known && ! $this->option('force')) {
                $this->line('✅ Source certificate unchanged since last sync.');

                return $this->reportExpiry($certPath);
            }

            $sourcePem = (string) file_get_contents($sourceCert);
            $keyPem    = is_file($sourceKey) ? (string) file_get_contents($sourceKey) : '';

            // Combined PEM (cert+key in one file) support: split on key header.
            if ($keyPem === '' && str_contains($sourcePem, 'PRIVATE KEY')) {
                $this->line('  Source file contains both certificate and key — splitting...');
                $keyPem = self::extractKey($sourcePem);
            }

            if ($keyPem === '') {
                $this->error("❌ No private key found for source: {$sourceKey}");
                return 1;
            }

            if (! CertKit::keyMatchesCert($sourcePem, $keyPem)) {
                $this->error('❌ Source certificate and private key do NOT match — refusing to import.');
                return 1;
            }

            $days = CertKit::daysUntilExpiry($sourcePem);

            if ($days !== null && $days < 0) {
                $this->error('❌ Source certificate is EXPIRED — refusing to import.');
                return 1;
            }

            $bakCert = CertKit::backupExisting($certPath);
            $bakKey  = CertKit::backupExisting($keyPath);

            CertKit::writeAtomic($certPath, $sourcePem, 0644);
            CertKit::writeAtomic($keyPath,  $keyPem,   0600);

            Cache::forever('qz-tray:cert-watch:hash', $hash);

            $msg = '[QZ Tray] Signing certificate re-imported from watch source ('.($days ?? '?').' days remaining).';
            $this->info('✅ '.$msg);

            if ($bakCert) {
                $this->line('  Backup: '.$bakCert);
            }

            if ($bakKey) {
                $this->line('  Backup: '.$bakKey);
            }

            $this->warn('ℹ️  IMPORTANT: the certificate fingerprint CHANGED (new leaf). Clients that pinned the old one must re-trust once — for Let\'s Encrypt chains this is usually seamless because QZ Tray trusts the public root, with no prompt at all.');

            if (config('qz-tray.logging.enabled')) {
                Log::info($msg);
            }

            return 0;
        }

        // ---------- Mode 2: no source configured → expiry report ----------
        $this->line('No watch source configured (qz-tray.certificate.watch.source_cert). Reporting expiry only.');

        return $this->reportExpiry($certPath);
    }

    protected function reportExpiry(string $certPath): int
    {
        if (! is_file($certPath)) {
            $this->error('❌ Signing certificate missing: '.$certPath);

            return 1;
        }

        $pem  = (string) file_get_contents($certPath);
        $days = CertKit::daysUntilExpiry($pem);
        $fp   = CertKit::fingerprintSha1Pretty($pem);

        if ($days === null) {
            $this->error('❌ Unable to parse certificate: '.$certPath);

            return 1;
        }

        $this->line('  Certificate: '.$certPath);
        $this->line('  Fingerprint (SHA-1): '.($fp ?? 'n/a'));
        $this->line('  Days remaining: '.$days);

        $warnWindow = (int) config('qz-tray.certificate.watch.warn_days', 30);

        if ($days < 0) {
            $this->error('❌ Certificate is EXPIRED — clients will see trust errors.');

            return 1;
        }

        if ($days <= $warnWindow) {
            $this->warn("⚠️  Certificate expires in {$days} days (warn window: {$warnWindow}).");

            return 1;
        }

        $this->line('✅ Certificate validity is healthy.');

        return 0;
    }

    /** Extract the first PEM private key block from a combined file. */
    protected static function extractKey(string $pem): string
    {
        preg_match('/-----BEGIN (?:RSA )?PRIVATE KEY-----.*?-----END (?:RSA )?PRIVATE KEY-----/s', $pem, $m);

        return $m[0] ?? '';
    }
}
