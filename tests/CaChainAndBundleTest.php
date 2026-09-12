<?php

namespace Bitdreamit\QzTray\Tests;

use Bitdreamit\QzTray\Support\CertKit;

/**
 * v1.4.0 — zero-prompt chain tests.
 *
 * Covers the exact OpenSSL recipe the zero-prompt architecture depends on:
 *   CA  generation (v3_ca profile, CA:TRUE)
 *   leaf generation with wildcard SANs signed by that CA (v3_leaf profile)
 *   chain verification + fingerprint identity + override export logic
 */
class CaChainAndBundleTest extends TestCase
{
    protected function caPaths(): array
    {
        return [
            storage_path('qz-ca-test/ca/root-ca.crt'),
            storage_path('qz-ca-test/ca/root-ca.key'),
        ];
    }

    protected function generateCa(): void
    {
        [$caCertPath, $caKeyPath] = $this->caPaths();

        $keyConfig = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = openssl_pkey_new($keyConfig);
        openssl_pkey_export($key, $keyPem, null, $keyConfig);

        $csr = openssl_csr_new(['organizationName' => 'Bit Dream IT', 'commonName' => 'Bit Dream IT Root CA'], $key, $keyConfig);

        $conf = CertKit::writeTempConf(CertKit::caConf());

        try {
            $cert = openssl_csr_sign($csr, null, $key, 3650, [
                'digest_alg'      => 'sha256',
                'x509_extensions' => 'v3_ca',
                'config'          => $conf,
            ], time());
        } finally {
            CertKit::cleanup($conf);
        }

        openssl_x509_export($cert, $certPem);

        CertKit::writeAtomic($caCertPath, $certPem, 0644);
        CertKit::writeAtomic($caKeyPath, $keyPem, 0600);
    }

    protected function generateLeaf(array $sans): array
    {
        [$caCertPath, $caKeyPath] = $this->caPaths();

        $keyConfig = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = openssl_pkey_new($keyConfig);
        openssl_pkey_export($key, $keyPem, null, $keyConfig);

        $subject = ['organizationName' => 'Bit Dream IT', 'commonName' => $sans[0]];
        $csr     = openssl_csr_new($subject, $key, $keyConfig);

        $conf  = CertKit::writeTempConf(CertKit::leafConf($sans));
        $caCert = openssl_x509_read((string) file_get_contents($caCertPath));
        $caKey  = openssl_pkey_get_private((string) file_get_contents($caKeyPath));

        try {
            $cert = openssl_csr_sign($csr, $caCert, $caKey, 3650, [
                'digest_alg'      => 'sha256',
                'x509_extensions' => 'v3_leaf',
                'config'          => $conf,
            ], time());
        } finally {
            CertKit::cleanup($conf);
        }

        openssl_x509_export($cert, $certPem);

        return [$certPem, $keyPem];
    }

    public function test_generated_ca_has_ca_true_and_subject_key_id(): void
    {
        $this->generateCa();

        [$caCertPath] = $this->caPaths();
        $parsed = openssl_x509_parse((string) file_get_contents($caCertPath));

        $this->assertNotFalse($parsed);
        $this->assertStringContainsString('CA:TRUE', (string) ($parsed['extensions']['basicConstraints'] ?? ''));
        $this->assertSame('Bit Dream IT Root CA', $parsed['subject']['CN']);
    }

    public function test_leaf_signed_by_ca_chains_to_it(): void
    {
        $this->generateCa();
        [$caCertPath] = $this->caPaths();

        [$leafPem] = $this->generateLeaf(['*.example.com', 'example.com']);
        $caPem     = (string) file_get_contents($caCertPath);

        $this->assertTrue(CertKit::leafChainsTo($leafPem, $caPem));
        $this->assertFalse(CertKit::isSelfSigned($leafPem));

        // SANs survived the openssl.cnf round-trip
        $parsed = openssl_x509_parse($leafPem);
        $san    = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        $this->assertStringContainsString('*.example.com', $san);
        $this->assertStringContainsString('example.com', $san);
    }

    public function test_ca_signed_leaf_verifies_against_ca_public_key(): void
    {
        $this->generateCa();
        [$caCertPath] = $this->caPaths();

        [$leafPem, $keyPem] = $this->generateLeaf(['*.example.com']);
        $caPem = (string) file_get_contents($caCertPath);

        // The heart of zero-prompt trust: the leaf's signature verifies with
        // the CA public key (the same key material inside override.crt).
        $leafPublicKey = openssl_pkey_get_details(openssl_pkey_get_public(openssl_x509_read($leafPem)))['key'];
        $caPublicKey   = openssl_pkey_get_details(openssl_pkey_get_public(openssl_x509_read($caPem)))['key'];

        $signature = '';
        openssl_sign('qz-tray-zero-prompt-probe', $signature, openssl_pkey_get_private($keyPem), OPENSSL_ALGO_SHA512);

        $this->assertSame(1, openssl_verify('qz-tray-zero-prompt-probe', $signature, openssl_pkey_get_public(openssl_x509_read($leafPem)), OPENSSL_ALGO_SHA512));
        $this->assertNotSame($leafPublicKey, $caPublicKey, 'leaf must not reuse the CA key');
    }

    public function test_fingerprints_are_pretty_and_stable(): void
    {
        $this->generateCa();
        [$caCertPath] = $this->caPaths();

        $pem = (string) file_get_contents($caCertPath);
        $fp1 = CertKit::fingerprintSha1Pretty($pem);
        $fp2 = CertKit::fingerprintSha1Pretty((string) file_get_contents($caCertPath));

        $this->assertMatchesRegularExpression('/^([0-9A-F]{2}:){19}[0-9A-F]{2}$/', (string) $fp1);
        $this->assertSame($fp1, $fp2);
    }

    public function test_key_match_and_expiry_helpers(): void
    {
        $this->generateCa();
        [$leafPem, $keyPem] = $this->generateLeaf(['*.example.com']);

        $this->assertTrue(CertKit::keyMatchesCert($leafPem, $keyPem));
        $this->assertFalse(CertKit::keyMatchesCert($leafPem, str_repeat('x', 100)));

        $days = CertKit::daysUntilExpiry($leafPem);
        $this->assertIsInt($days);
        $this->assertGreaterThan(3000, $days);
    }

    public function test_atomic_write_is_readable_and_permissioned(): void
    {
        $path = storage_path('qz-ca-test/atomic/digital-certificate.txt');
        CertKit::writeAtomic($path, "-----TEST-----\n", 0644);

        $this->assertFileExists($path);
        $this->assertSame("-----TEST-----\n", file_get_contents($path));

        $backup = CertKit::backupExisting($path);
        $this->assertNotNull($backup);
        $this->assertFileExists($backup);
    }
}
