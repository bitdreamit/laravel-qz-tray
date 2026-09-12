<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Bitdreamit\QzTray\Support\CertKit;
use Illuminate\Console\Command;

/**
 * qz:generate-certificate — generate the QZ Tray site (leaf) certificate.
 *
 * v1.4.0 additions:
 *   --domain=*.example.com,example.com   Add wildcard + SAN entries so ONE
 *                                        certificate covers every tenant
 *                                        subdomain (identical fingerprint on
 *                                        all hosts = clients trust once).
 *   --ca                                 Sign the leaf with the project's own
 *                                        Root CA (qz:generate-ca). Clients
 *                                        that deployed that CA as override.crt
 *                                        never see the "Untrusted website"
 *                                        prompt. Falls back to plain
 *                                        self-signed if no CA exists yet.
 */
class GenerateCertificate extends Command
{
    protected $signature = 'qz:generate-certificate
                            {--force : Force generation even if certificate exists}
                            {--show  : Show certificate details after generation}
                            {--domain= : Comma/semicolon-separated SAN domains, e.g. *.example.com,example.com}
                            {--ca : Sign with the local Root CA when available (auto-creates nothing; run qz:generate-ca first)}';

    protected $description = 'Generate SSL certificate for QZ Tray (v1.4: optional wildcard SANs + own-CA chaining for zero-prompt trust)';

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

        $certConfig = (array) config('qz-tray.certificate', []);
        $digest     = CertKit::safeDigest((string) ($certConfig['algorithm'] ?? 'sha256'));
        $bits       = (int) ($certConfig['key_bits'] ?? 2048);
        $days       = (int) ($certConfig['validity_days'] ?? 7300);

        $opensslConfig = [
            'digest_alg'       => $digest,
            'private_key_bits' => $bits,
            'private_key_type' => (int) ($certConfig['key_type'] ?? OPENSSL_KEYTYPE_RSA),
        ];

        // ---- Resolve SAN domains (config default + --domain override) ----
        $configuredSans = (array) ($certConfig['san_domains'] ?? []);
        $optionSans     = $this->sanListFromOption((string) $this->option('domain'));
        $sans           = $this->normalizeSans($optionSans ?: $configuredSans);

        // ---- Resolve signing CA (--ca) ----
        $caCertPath = (string) (config('qz-tray.certificate.ca.cert_path') ?: storage_path('qz/ca/qz-root-ca.crt'));
        $caKeyPath  = (string) (config('qz-tray.certificate.ca.key_path')  ?: storage_path('qz/ca/qz-root-ca.key'));
        $useCa      = (bool) $this->option('ca') && is_file($caCertPath) && is_file($caKeyPath);

        $this->info('🔐 Generating QZ Tray certificate...');

        $this->line('  Generating private key...');
        $privateKey = openssl_pkey_new($opensslConfig);
        if (! $privateKey) {
            $err = openssl_error_string() ?: 'unknown error';
            $this->error('❌ Failed to generate private key: ' . $err);
            return 1;
        }

        openssl_pkey_export($privateKey, $privateKeyPEM, null, $opensslConfig);

        $subject = (array) ($certConfig['subject'] ?? [
            'countryName'         => 'US',
            'stateOrProvinceName' => 'NY',
            'localityName'        => 'Canastota',
            'organizationName'    => 'QZ Industries, LLC',
            'commonName'          => 'QZ Tray Site Certificate',
            'emailAddress'        => 'support@qz.io',
        ]);

        // When SANs are present, CN should be a human-friendly primary domain
        // (or the org name); QZ Tray's dialog shows the subject, so keep it
        // recognisable to cashiers/print operators.
        if ($sans !== []) {
            $primary = $this->primarySan($sans);
            if ($primary !== null) {
                $subject['commonName'] = $primary;
            }
        }

        $this->line('  Creating certificate signing request...');
        $csr = openssl_csr_new($subject, $privateKey, $opensslConfig);
        if (! $csr) {
            $err = openssl_error_string() ?: 'unknown error';
            $this->error('❌ Failed to create CSR: ' . $err);
            return 1;
        }

        // Extensions config: SANs (if any) + v3_leaf profile.
        $confBody = CertKit::leafConf($sans);
        $confPath = CertKit::writeTempConf($confBody);
        $serial   = time();

        try {
            if ($useCa) {
                $this->line('  Signing with local Root CA: '.$caCertPath);

                $caCert = openssl_x509_read((string) file_get_contents($caCertPath));
                $caKey  = openssl_pkey_get_private((string) file_get_contents($caKeyPath));

                if (! $caCert || ! $caKey) {
                    $this->error('❌ Root CA is unreadable. Re-run: php artisan qz:generate-ca');
                    return 1;
                }

                $cert = openssl_csr_sign($csr, $caCert, $caKey, $days, [
                    'digest_alg'      => $digest,
                    'x509_extensions' => 'v3_leaf',
                    'config'          => $confPath,
                ], $serial);
            } else {
                if ($this->option('ca')) {
                    $this->warn('  ⚠️  No local Root CA found — falling back to self-signed. Run `php artisan qz:generate-ca` for zero-prompt trust.');
                }

                $this->line('  Self-signing certificate (no CA)...');
                $cert = openssl_csr_sign($csr, null, $privateKey, $days, [
                    'digest_alg'      => $digest,
                    'x509_extensions' => $sans !== [] ? 'v3_leaf' : null,
                    'config'          => $confPath,
                ], $serial);
            }
        } finally {
            CertKit::cleanup($confPath);
        }

        if (! $cert) {
            $err = openssl_error_string() ?: 'unknown error';
            $this->error('❌ Failed to sign certificate: ' . $err);
            return 1;
        }

        openssl_x509_export($cert, $certificatePEM);

        // Backup the pair we are about to replace so a bad rotation can be
        // rolled back (fingerprint changes = one re-trust per client).
        $oldCert = is_file($certPath) ? CertKit::backupExisting($certPath) : null;
        $oldKey  = is_file($keyPath)  ? CertKit::backupExisting($keyPath)  : null;

        CertKit::writeAtomic($certPath, $certificatePEM, 0644);
        CertKit::writeAtomic($keyPath,  $privateKeyPEM,   0600);

        $this->info('✅ Certificate generated successfully!');
        $this->line('  📄 Certificate: '.$certPath.($oldCert ? '   (previous saved as '.basename($oldCert).')' : ''));
        $this->line('  🔑 Private key: '.$keyPath.($oldKey ? '   (previous saved as '.basename($oldKey).')' : ''));
        $this->line('  ⏳ Validity: '.$days.' days ('.round($days / 365).' years)');

        if ($sans !== []) {
            $this->line('  🌐 SAN domains: '.implode(', ', $sans));
        }

        if ($useCa) {
            $this->line('  🔗 Issuer: local Root CA → clients deploying override.crt trust this certificate silently.');
        } else {
            $this->line('  🔗 Issuer: self-signed → clients click "Always Allow" once per machine, or deploy the CA flow (docs/zero-prompt.md).');
        }

        if ($this->option('show')) {
            $this->showCertificateDetails($certPath);
        }

        $this->testCertificate($certPath, $keyPath);

        return 0;
    }

    /**
     * Parse the --domain option: supports comma and semicolon separators and
     * repeated wildcards. Returns [] when the option was not provided.
     */
    protected function sanListFromOption(string $option): array
    {
        if (trim($option) === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', strtolower($option)) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    /** De-duplicate SANs, drop scheme/path junk, keep wildcard entries. */
    protected function normalizeSans(array $sans): array
    {
        $clean = [];

        foreach ($sans as $san) {
            $san = trim(strtolower((string) $san));
            $san = preg_replace('#^https?://#', '', $san) ?? $san;
            $san = rtrim($san, '/');
            $san = preg_replace('/:\d+$/', '', $san) ?? $san; // strip ports

            // FILTER_FLAG_HOSTNAME rejects the leading "*." of wildcards, so
            // validate the bare domain part and re-attach the wildcard.
            $wild  = str_starts_with($san, '*.');
            $bare  = $wild ? substr($san, 2) : $san;
            $valid = $bare !== '' && filter_var($bare, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);

            if (! $valid) {
                continue;
            }

            $clean[$wild ? '*.'.$bare : $bare] = true;
        }

        return array_keys($clean);
    }

    /** Prefer a wildcard entry as CN so the subject reads like a tenant domain. */
    protected function primarySan(array $sans): ?string
    {
        foreach ($sans as $san) {
            if (str_starts_with($san, '*.')) {
                return $san;
            }
        }

        return $sans[0] ?? null;
    }

    protected function showCertificateDetails(string $certPath): void
    {
        $certData = openssl_x509_parse((string) file_get_contents($certPath));
        if (! $certData) {
            $this->warn('Could not parse certificate details.');
            return;
        }

        $this->newLine();
        $this->info('📋 Certificate Details:');
        $this->line('  Subject:    '.$certData['name']);
        $this->line('  Issuer:     '.($certData['issuer']['CN'] ?? $certData['name']));
        $this->line('  Valid From: '.date('Y-m-d H:i:s', $certData['validFrom_time_t']));
        $this->line('  Valid Until: '.date('Y-m-d H:i:s', $certData['validTo_time_t']));
        $this->line('  Serial:     '.$certData['serialNumber']);
        $this->line('  Algorithm:  '.$certData['signatureTypeSN']);

        if (! empty($certData['extensions']['subjectAltName'])) {
            $this->line('  SAN:        '.$certData['extensions']['subjectAltName']);
        }

        $fp = CertKit::fingerprintSha1Pretty((string) file_get_contents($certPath));
        if ($fp) {
            $this->line('  Fingerprint (SHA-1, matches QZ Tray dialog): '.$fp);
        }
    }

    protected function testCertificate(string $certPath, string $keyPath): void
    {
        $this->newLine();
        $this->info('🧪 Testing certificate...');

        $cert = openssl_x509_read((string) file_get_contents($certPath));
        $key  = openssl_pkey_get_private((string) file_get_contents($keyPath));

        if (! $cert || ! $key) {
            $this->error('❌ Certificate or key is invalid.');
            return;
        }

        $this->line('  ✅ Certificate format valid');
        $this->line('  ✅ Private key format valid');

        if (CertKit::keyMatchesCert((string) file_get_contents($certPath), (string) file_get_contents($keyPath))) {
            $this->line('  ✅ Certificate ↔ private key match');
        } else {
            $this->error('  ❌ Certificate does NOT match private key!');
        }

        $testData  = 'test_qz_tray_'.time();
        $signature = '';
        if (openssl_sign($testData, $signature, $key, OPENSSL_ALGO_SHA512)) {
            $this->line('  ✅ SHA512 signing works');
        } else {
            $err = openssl_error_string() ?: 'unknown error';
            $this->warn('  ⚠️  SHA512 signing failed: ' . $err);
        }
    }
}
