<?php

namespace Bitdreamit\QzTray\Tests;

/**
 * v1.3.0 — qz:certificate:export / qz:certificate:import round-trips plus the
 * /qz/status fingerprint disclosure that lets operators verify all
 * subdomains present the same keypair.
 *
 * The fixture pair is generated with the same PHP OpenSSL recipe the
 * qz:generate-certificate command uses — no openssl binary, no network.
 */
class CertificateSharingTest extends TestCase
{
    protected string $certPath;

    protected string $keyPath;

    protected string $pfxPath;

    /** @var array<int, string> */
    protected array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->certPath = storage_path('qz-testing-certificate.txt');
        $this->keyPath  = storage_path('qz-testing-private-key.pem');
        $this->pfxPath  = storage_path('qz-testing-export.pfx');
        $this->scratch  = [$this->pfxPath];

        config([
            'qz-tray.cert_path' => $this->certPath,
            'qz-tray.key_path'  => $this->keyPath,
        ]);

        $this->writeFreshPair();
    }

    protected function tearDown(): void
    {
        foreach (array_merge([$this->certPath, $this->keyPath], $this->scratch) as $file) {
            @unlink($file);
        }

        // Import backups created during the tests.
        foreach (glob(dirname($this->certPath).'/qz-testing-*.bak-*') ?: [] as $backup) {
            @unlink($backup);
        }

        parent::tearDown();
    }

    protected function writeFreshPair(string $cn = 'QZ Test Cert'): void
    {
        $keyConfig = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($keyConfig);
        openssl_pkey_export($privateKey, $privateKeyPem);
        $csr  = openssl_csr_new(['commonName' => $cn], $privateKey, $keyConfig);
        $cert = openssl_csr_sign($csr, null, $privateKey, 365, $keyConfig, time());
        openssl_x509_export($cert, $certPem);

        file_put_contents($this->certPath, $certPem);
        file_put_contents($this->keyPath, $privateKeyPem);
    }

    public function test_export_then_import_roundtrip_preserves_the_keypair(): void
    {
        $this->artisan('qz:certificate:export', [
            '--out'      => $this->pfxPath,
            '--password' => 'test-passphrase-123',
            '--force'    => true,
        ])->assertExitCode(0);

        $this->assertFileExists($this->pfxPath);

        $originalFingerprint = openssl_x509_fingerprint('file://'.$this->certPath, 'sha1');

        // Simulate a fresh subdomain: no pair installed yet.
        @unlink($this->certPath);
        @unlink($this->keyPath);

        $this->artisan('qz:certificate:import', [
            '--pfx'      => $this->pfxPath,
            '--password' => 'test-passphrase-123',
        ])->assertExitCode(0);

        $this->assertFileExists($this->certPath);
        $this->assertFileExists($this->keyPath);

        // Same fingerprint = same trust decision covers both installs.
        $this->assertSame(
            $originalFingerprint,
            openssl_x509_fingerprint('file://'.$this->certPath, 'sha1'),
            'Imported certificate must be byte-equivalent to the exported one'
        );

        // And the key must still sign.
        $signature = '';
        $this->assertTrue(openssl_sign('roundtrip', $signature, openssl_pkey_get_private('file://'.$this->keyPath), OPENSSL_ALGO_SHA512));
        $this->assertSame(1, openssl_verify('roundtrip', $signature, openssl_pkey_get_public('file://'.$this->certPath), OPENSSL_ALGO_SHA512));
    }

    public function test_export_refuses_mismatched_cert_and_key(): void
    {
        file_put_contents($this->keyPath, $this->makeAnotherKeyPem());

        $this->artisan('qz:certificate:export', [
            '--out'      => $this->pfxPath,
            '--password' => 'test-passphrase-123',
            '--force'    => true,
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->pfxPath);
    }

    public function test_import_rejects_mismatched_key_without_touching_existing_pair(): void
    {
        $beforeCert = file_get_contents($this->certPath);
        $beforeKey  = file_get_contents($this->keyPath);

        $otherCert = storage_path('qz-testing-other-cert.pem');
        file_put_contents($otherCert, $this->makeAnotherCertPem());
        $this->scratch[] = $otherCert;

        $this->artisan('qz:certificate:import', [
            '--cert' => $otherCert,
            '--key'  => $this->keyPath, // existing key does NOT match otherCert
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame($beforeCert, file_get_contents($this->certPath), 'Existing cert must be untouched on failed import');
        $this->assertSame($beforeKey, file_get_contents($this->keyPath), 'Existing key must be untouched on failed import');
    }

    public function test_import_without_force_refuses_to_replace_existing_pair(): void
    {
        $originalFingerprint = openssl_x509_fingerprint('file://'.$this->certPath, 'sha1');

        $otherCert = storage_path('qz-testing-other-cert.pem');
        $otherKey  = storage_path('qz-testing-other-key.pem');
        file_put_contents($otherCert, $this->makeAnotherCertPem());
        file_put_contents($otherKey, $this->makeAnotherKeyPem());
        $this->scratch[] = $otherCert;
        $this->scratch[] = $otherKey;

        $this->artisan('qz:certificate:import', [
            '--cert' => $otherCert,
            '--key'  => $otherKey,
        ])->assertExitCode(1);

        $this->assertSame(
            $originalFingerprint,
            openssl_x509_fingerprint('file://'.$this->certPath, 'sha1'),
            'Existing certificate must remain installed without --force'
        );
    }

    public function test_import_with_force_replaces_pair_and_keeps_backup(): void
    {
        $originalFingerprint = openssl_x509_fingerprint('file://'.$this->certPath, 'sha1');

        $otherCert = storage_path('qz-testing-other-cert.pem');
        $otherKey  = storage_path('qz-testing-other-key.pem');
        file_put_contents($otherCert, $this->makeAnotherCertPem());
        file_put_contents($otherKey, $this->makeAnotherKeyPem());
        $this->scratch[] = $otherCert;
        $this->scratch[] = $otherKey;

        $this->artisan('qz:certificate:import', [
            '--cert' => $otherCert,
            '--key'  => $otherKey,
            '--force' => true,
        ])->assertExitCode(0);

        $newFingerprint = openssl_x509_fingerprint('file://'.$this->certPath, 'sha1');

        $this->assertNotSame($originalFingerprint, $newFingerprint, 'Pair must be replaced with --force');

        $backups = glob(dirname($this->certPath).'/qz-testing-certificate.txt.bak-*') ?: [];
        $this->assertNotEmpty($backups, 'Import must back up the replaced certificate');

        $backupFingerprint = openssl_x509_fingerprint('file://'.$backups[0], 'sha1');
        $this->assertSame($originalFingerprint, $backupFingerprint, 'Backup must contain the previous certificate');
    }

    public function test_import_rejects_invalid_option_combinations(): void
    {
        $this->artisan('qz:certificate:import')->assertExitCode(1);

        $otherCert = storage_path('qz-testing-other-cert.pem');
        $otherKey  = storage_path('qz-testing-other-key.pem');
        $otherPfx  = storage_path('qz-testing-other.pfx');
        file_put_contents($otherCert, $this->makeAnotherCertPem());
        file_put_contents($otherKey, $this->makeAnotherKeyPem());
        file_put_contents($otherPfx, 'not-a-real-pfx');
        $this->scratch[] = $otherCert;
        $this->scratch[] = $otherKey;
        $this->scratch[] = $otherPfx;

        $this->artisan('qz:certificate:import', [
            '--cert' => $otherCert,
            '--key'  => $otherKey,
            '--pfx'  => $otherPfx,
        ])->assertExitCode(1);

        $this->artisan('qz:certificate:import', ['--cert' => $otherCert])->assertExitCode(1);
    }

    public function test_status_exposes_fingerprint_and_self_signed_flag(): void
    {
        $expected = openssl_x509_fingerprint('file://'.$this->certPath, 'sha1');

        $this->getJson('/qz/status')
            ->assertOk()
            ->assertJsonPath('certificate_details.self_signed', true)
            ->assertJsonPath('certificate_details.fingerprint_sha1', implode(':', str_split($expected, 2)))
            ->assertJsonPath('certificate_details.shared_path', false);
    }

    protected function makeAnotherKeyPem(): string
    {
        $key = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    protected function makeAnotherCertPem(): string
    {
        $keyConfig = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $key = openssl_pkey_new($keyConfig);
        $csr = openssl_csr_new(['commonName' => 'QZ Unrelated Cert'], $key, $keyConfig);
        $cert = openssl_csr_sign($csr, null, $key, 365, $keyConfig, time());
        openssl_x509_export($cert, $pem);

        return $pem;
    }
}
