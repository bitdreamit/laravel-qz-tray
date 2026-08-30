<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;

/**
 * qz:certificate:import — install an EXISTING certificate + private key as
 * the QZ Tray signing pair. Two sources:
 *
 *  1. A PEM pair — this is how you plug in a REAL CA-signed certificate and
 *     make the QZ Tray "Untrusted website" prompt disappear completely:
 *
 *       php artisan qz:certificate:import \
 *         --cert=/etc/letsencrypt/live/app.example.com/fullchain.pem \
 *         --key=/etc/letsencrypt/live/app.example.com/privkey.pem
 *
 *     (cPanel/AutoSSL equivalents live under /var/cpanel/ssl/apache_tls/...)
 *     Use the FULLCHAIN file — QZ Tray needs the intermediate CA to build
 *     the chain to a root it trusts.
 *
 *  2. A password-protected .pfx produced by qz:certificate:export on the
 *     main domain — how all subdomains end up with the SAME self-signed
 *     keypair, so clients only click "Always Allow" once:
 *
 *       php artisan qz:certificate:import --pfx=qz-certificate-export.pfx --password=...
 *
 * The existing pair is backed up before it is replaced. Fingerprints are
 * printed after import so the result can be compared against other
 * subdomains (identical fingerprints = one shared trust decision).
 */
class ImportCertificate extends Command
{
    protected $signature = 'qz:certificate:import
                            {--cert= : Path to a PEM certificate (use fullchain.pem for CA certs)}
                            {--key=  : Path to the matching PEM private key}
                            {--pfx=  : Path to a .pfx produced by qz:certificate:export}
                            {--password= : Password for --pfx (will prompt securely if omitted)}
                            {--force : Import even if a certificate already exists (backs it up)}';

    protected $description = 'Import an existing certificate (CA-signed fullchain or shared .pfx) as the QZ Tray signing pair';

    public function handle(): int
    {
        if (! extension_loaded('openssl')) {
            $this->error('❌ OpenSSL extension is not enabled.');

            return 1;
        }

        $certIn = (string) $this->option('cert');
        $keyIn  = (string) $this->option('key');
        $pfxIn  = (string) $this->option('pfx');

        $usingPem = ($certIn !== '' || $keyIn !== '');
        $usingPfx = ($pfxIn !== '');

        if ($usingPem === $usingPfx) {
            $this->error('❌ Provide EITHER --cert=... --key=... (PEM pair) OR --pfx=... — not both, not neither.');

            return 1;
        }

        if ($usingPem && ($certIn === '' || $keyIn === '')) {
            $this->error('❌ --cert= and --key= must be provided together (they are a matched pair).');

            return 1;
        }

        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath  = config('qz-tray.key_path',  storage_path('qz/private-key.pem'));

        if (! $this->option('force') && is_file($certPath) && is_file($keyPath)) {
            $this->error('❌ A certificate already exists. Re-run with --force to replace it (the current pair will be backed up).');
            $this->line('  Existing certificate: '.$certPath);

            return 1;
        }

        if ($pfxIn !== '') {
            $result = $this->loadFromPfx($pfxIn);
        } else {
            $result = $this->loadFromPem($certIn, $keyIn);
        }

        if ($result === null) {
            return 1;
        }

        [$certPem, $keyPem, $sourceLabel] = $result;

        $cert = openssl_x509_read($certPem);
        $key  = openssl_pkey_get_private($keyPem);

        if (! $cert || ! $key || ! openssl_x509_check_private_key($cert, $key)) {
            $this->error('❌ The private key does not match the certificate — refusing to install a broken pair.');

            return 1;
        }

        $parsed = openssl_x509_parse($certPem);
        if (! $parsed || ! isset($parsed['validTo_time_t'])) {
            $this->error('❌ Certificate cannot be parsed.');

            return 1;
        }

        $daysLeft = (int) now()->diffInDays(\Carbon\Carbon::createFromTimestamp($parsed['validTo_time_t']), false);
        if ($daysLeft <= 0) {
            $this->error('❌ Certificate expired on '.date('Y-m-d', $parsed['validTo_time_t']).'. Cannot import.');

            return 1;
        }

        if ($daysLeft < 60 && $daysLeft > 0) {
            $this->warn("⚠️  Certificate expires in {$daysLeft} days — plan renewal, and remember to re-import (or re-run this command) after renewal.");
        }

        $this->backupExisting($certPath, $keyPath);

        foreach ([$certPath, $keyPath] as $target) {
            $dir = dirname($target);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        if (file_put_contents($certPath, $certPem) === false || file_put_contents($keyPath, $keyPem) === false) {
            $this->error('❌ Failed writing certificate files — check permissions on '.dirname($certPath));

            return 1;
        }
        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        $this->info("✅ Certificate imported from {$sourceLabel}");
        $this->line("  📄 Certificate: {$certPath}");
        $this->line("  🔑 Private key: {$keyPath}");
        $this->newLine();

        $this->showCertificateSummary($parsed, $certPem);

        return 0;
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    protected function loadFromPem(string $certIn, string $keyIn): ?array
    {
        if (! is_file($certIn) || ! is_file($keyIn)) {
            $this->error("❌ File not found. Cert: {$certIn} Key: {$keyIn}");

            return null;
        }

        $certPem = (string) file_get_contents($certIn);
        $keyPem  = (string) file_get_contents($keyIn);

        if (! str_contains($certPem, '-----BEGIN CERTIFICATE-----')) {
            $this->error('❌ '.$certIn.' does not look like a PEM certificate (no BEGIN CERTIFICATE block).');

            return null;
        }

        // A CA-issued leaf alone cannot be validated by QZ Tray — it needs the
        // intermediate(s) to build the chain to a trusted root. fullchain.pem
        // contains leaf + intermediates; cert.pem (Let's Encrypt) does not.
        $chainCount = preg_match_all('/-----BEGIN CERTIFICATE-----/', $certPem) ?: 0;
        $looksSelfSigned = $this->isSelfSigned($certPem);
        if ($chainCount === 1 && ! $looksSelfSigned) {
            $this->warn('⚠️  This certificate file contains ONLY the leaf certificate (no intermediate CA).');
            $this->warn('    QZ Tray may not be able to validate it. Prefer the fullchain.pem file,');
            $this->warn('    or append the intermediate certificates to the file before importing.');
        }

        return [$certPem, $keyPem, basename($certIn)];
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null
     */
    protected function loadFromPfx(string $pfxIn): ?array
    {
        if (! is_file($pfxIn)) {
            $this->error("❌ File not found: {$pfxIn}");

            return null;
        }

        $password = (string) ($this->option('password') ?: $this->secret('PKCS#12 password'));

        $pkcs12 = (string) file_get_contents($pfxIn);
        $parsed = [];

        if (! openssl_pkcs12_read($pkcs12, $parsed, $password)) {
            $err = openssl_error_string() ?: 'unknown error';
            $this->error("❌ Could not read the .pfx (wrong password or corrupt file): {$err}");

            return null;
        }

        $certPem = (string) ($parsed['cert'] ?? '');
        $keyPem  = (string) ($parsed['pkey'] ?? '');

        if ($certPem === '' || $keyPem === '') {
            $this->error('❌ The .pfx does not contain both a certificate and a private key.');

            return null;
        }

        // Re-attach any bundled intermediates so the chain survives.
        $extras = $parsed['extracerts'] ?? [];
        if (is_array($extras) && $extras !== []) {
            $certPem .= "\n".implode("\n", $extras);
        }

        return [$certPem, $keyPem, basename($pfxIn).' (PKCS#12)'];
    }

    protected function isSelfSigned(string $certPem): bool
    {
        $parsed = openssl_x509_parse($certPem);

        if (! $parsed || ! isset($parsed['subject'], $parsed['issuer'])) {
            return false;
        }

        return $parsed['subject'] === $parsed['issuer'];
    }

    protected function backupExisting(string $certPath, string $keyPath): void
    {
        $stamp = now()->format('Ymd-His');

        foreach ([$certPath, $keyPath] as $file) {
            if (is_file($file)) {
                $backup = $file.'.bak-'.$stamp;
                copy($file, $backup);
                chmod($backup, 0600);
                $this->line('  💾 Backed up: '.$backup);
            }
        }
    }

    protected function showCertificateSummary(array $parsed, string $certPem): void
    {
        $subjectCn = $parsed['subject']['CN'] ?? '—';
        $org       = $parsed['subject']['O']  ?? '—';
        $issuerCn  = $parsed['issuer']['CN'] ?? '—';
        $issuerOrg = $parsed['issuer']['O']  ?? '—';
        $selfSigned = $this->isSelfSigned($certPem);

        $this->line('📋 Certificate details:');
        $this->line("  Subject:     CN={$subjectCn}, O={$org}");
        $this->line("  Issuer:      CN={$issuerCn}, O={$issuerOrg}");
        $this->line('  Valid from:  '.date('Y-m-d H:i:s', $parsed['validFrom_time_t']));
        $this->line('  Valid until: '.date('Y-m-d H:i:s', $parsed['validTo_time_t']));

        $sha1 = openssl_x509_fingerprint($certPem, 'sha1');
        if ($sha1) {
            $this->line('  Fingerprint: '.implode(':', str_split($sha1, 2)));
        }

        $this->newLine();

        if ($selfSigned) {
            $this->info('🔐 This is a SELF-SIGNED certificate — expect QZ Tray to show the');
            $this->line('   "Untrusted website" dialog the first time. That is expected:');
            $this->line('   click "Always Allow" ONCE per client machine. Every site and every');
            $this->line('   subdomain presenting this SAME certificate is then trusted');
            $this->line('   automatically — the prompt is keyed to the certificate fingerprint,');
            $this->line('   not to the domain.');
            $this->newLine();
            $this->line('   ✅ To remove the prompt entirely, import a real CA-signed');
            $this->line('      certificate instead (e.g. your Let\'s Encrypt fullchain.pem):');
            $this->line('      php artisan qz:certificate:import --cert=fullchain.pem --key=privkey.pem --force');
        } else {
            $this->info('🔐 This certificate is issued by a CA (issuer differs from subject).');
            $this->line('   If the issuing CA is in QZ Tray\'s trust store (all major public CAs');
            $this->line('   are — including Let\'s Encrypt), clients connect SILENTLY with no');
            $this->line('   trust prompt at all. Nothing else to do.');
        }

        $this->newLine();
        $this->line('   Verify on other subdomains: php artisan qz:doctor');
        $this->line('   (identical SHA-1 fingerprints = shared keypair = one trust click)');
    }
}
