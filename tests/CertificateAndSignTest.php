<?php

namespace Bitdreamit\QzTray\Tests;

class CertificateAndSignTest extends TestCase
{
    protected string $certPath;

    protected string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certPath = storage_path('qz-testing-certificate.txt');
        $this->keyPath  = storage_path('qz-testing-private-key.pem');

        config([
            'qz-tray.cert_path' => $this->certPath,
            'qz-tray.key_path'  => $this->keyPath,
        ]);

        // Throwaway self-signed pair, generated with the same OpenSSL recipe
        // the package command uses — no network, no fixtures.
        $keyConfig = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $privateKey = openssl_pkey_new($keyConfig);
        openssl_pkey_export($privateKey, $privateKeyPem);
        $csr  = openssl_csr_new(['commonName' => 'QZ Test Cert'], $privateKey, $keyConfig);
        $cert = openssl_csr_sign($csr, null, $privateKey, 365, $keyConfig, time());
        openssl_x509_export($cert, $certPem);

        file_put_contents($this->certPath, $certPem);
        file_put_contents($this->keyPath, $privateKeyPem);
    }

    protected function tearDown(): void
    {
        @unlink($this->certPath);
        @unlink($this->keyPath);

        parent::tearDown();
    }

    public function test_certificate_endpoint_serves_the_certificate(): void
    {
        $response = $this->get('/qz/certificate');

        $response->assertOk();
        $this->assertStringEqualsFile($this->certPath, $response->getContent());
    }

    public function test_certificate_endpoint_404s_when_missing(): void
    {
        @unlink($this->certPath);

        $this->get('/qz/certificate')->assertNotFound();
    }

    public function test_sign_endpoint_returns_verifiable_sha512_signature(): void
    {
        $data     = 'qz-tray-signature-payload-'.time();
        $response = $this->postJson('/qz/sign', ['data' => $data]);

        $response->assertOk();

        $signature = base64_decode($response->getContent(), true);

        $this->assertNotFalse($signature, 'Body must be valid base64');

        $verified = openssl_verify(
            $data,
            $signature,
            openssl_pkey_get_public('file://'.$this->certPath),
            OPENSSL_ALGO_SHA512
        );

        $this->assertSame(1, $verified, 'Signature must verify against the served certificate');
    }

    public function test_sign_rejects_empty_and_non_string_data(): void
    {
        $this->postJson('/qz/sign', ['data' => ''])->assertStatus(400);
        $this->postJson('/qz/sign', ['data' => ['array' => 'value']])->assertStatus(400);
        $this->postJson('/qz/sign', [])->assertStatus(400);
    }

    public function test_status_endpoint_reports_operational_with_pair_present(): void
    {
        $this->getJson('/qz/status')
            ->assertOk()
            ->assertJsonPath('status', 'operational')
            ->assertJsonPath('version', \Bitdreamit\QzTray\QzTrayServiceProvider::VERSION);
    }

    public function test_status_endpoint_reports_degraded_when_key_missing(): void
    {
        @unlink($this->keyPath);

        $this->getJson('/qz/status')
            ->assertOk()
            ->assertJsonPath('status', 'degraded');
    }
}
