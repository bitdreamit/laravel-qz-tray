<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QzController extends Controller
{
    /**
     * Get QZ Tray certificate
     */
    public function certificate()
    {
        $certPath = storage_path('qz/certificate.pem');

        if (! file_exists($certPath)) {
            // Auto-generate certificate if it doesn't exist
            $this->generateCertificate();
        }

        $cert = file_get_contents($certPath);

        return response($cert, 200, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Sign data for QZ Tray
     */
    public function sign(Request $request)
    {
        $request->validate([
            'data' => 'required|string',
        ]);

        $data = $request->input('data');
        $keyPath = storage_path('qz/private-key.pem');

        if (! file_exists($keyPath)) {
            return response('Private key not found', 500);
        }

        $privateKey = file_get_contents($keyPath);

        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature));
    }

    /**
     * Generate SSL certificate
     */
    private function generateCertificate()
    {
        $certDir = storage_path('qz');
        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }

        $config = [
            'digest_alg' => 'sha512',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Generate key pair
        $keypair = openssl_pkey_new($config);
        openssl_pkey_export($keypair, $privateKey);

        // Create CSR
        $csr = openssl_csr_new([
            'commonName' => config('app.name', 'Laravel QZ Tray'),
            'countryName' => 'US',
            'organizationName' => 'Laravel QZ Tray',
        ], $keypair);

        // Sign certificate (self-signed)
        $cert = openssl_csr_sign($csr, null, $keypair, 365, $config, time());

        // Export certificate
        openssl_x509_export($cert, $certificate);

        // Save files
        file_put_contents($certDir.'/certificate.pem', $certificate);
        file_put_contents($certDir.'/private-key.pem', $privateKey);
    }

    /**
     * Test PDF generation
     */
    public function testPdf()
    {
        // Generate a simple PDF for testing
        $pdf = \PDF::loadView('test.pdf', [
            'title' => 'QZ Tray Test',
            'content' => 'This is a test PDF for QZ Tray printing.',
            'timestamp' => now()->toDateTimeString(),
        ]);

        return $pdf->stream('test.pdf');
    }
}
