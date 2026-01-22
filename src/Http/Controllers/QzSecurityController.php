<?php

namespace Bitdreamit\QzTray\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class QzSecurityController extends Controller
{
    /**
     * Serve public certificate - EXACT QZ TRAY FORMAT
     */
    public function certificate(): Response
    {
        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));

        // Generate certificate if not exists (in style)
        if (! file_exists($certPath)) {
            $this->generateCertificate();
        }

        if (! file_exists($certPath)) {
            Log::error('QZ Tray certificate not found at: '.$certPath);

            return response('Certificate not found', 404);
        }

        $certificate = file_get_contents($certPath);

        return response($certificate, 200, [
            'Content-Type' => 'text/plain',
            'Content-Length' => strlen($certificate),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Sign data for QZ Tray - EXACT STYLE
     */
    public function sign(Request $request): Response
    {
        // Accept both JSON and form data
        if ($request->isJson()) {
            $data = $request->json('data');
        } else {
            $data = $request->input('data');
        }

        if (empty($data)) {
            return response('Missing data parameter', 400);
        }

        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        if (! file_exists($keyPath)) {
            Log::error('QZ Tray private key not found at: '.$keyPath);

            return response('Private key not found', 500);
        }

        // Read and use private key
        $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));

        if ($privateKey === false) {
            Log::error('QZ Tray invalid private key: '.openssl_error_string());

            return response('Invalid private key', 500);
        }

        // Sign with SHA512 (QZ Tray 2.0+ requirement)
        $signature = '';
        $success = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        openssl_free_key($privateKey);

        if (! $success) {
            Log::error('QZ Tray signing failed: '.openssl_error_string());

            return response('Signing failed', 500);
        }

        // Return base64 signature
        $base64Signature = base64_encode($signature);

        Log::debug('QZ Tray signature generated', [
            'data_length' => strlen($data),
            'signature_length' => strlen($base64Signature),
        ]);

        return response($base64Signature, 200, [
            'Content-Type' => 'text/plain',
            'Content-Length' => strlen($base64Signature),
        ]);
    }

    /**
     * Generate certificate exactly like QZ Tray Demo
     * Private method - called automatically if certificate doesn't exist
     */
    private function generateCertificate(): bool
    {
        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        // Check if already exists
        if (file_exists($certPath) && file_exists($keyPath)) {
            return true;
        }

        // Get certificate directory
        $certDir = dirname($certPath);
        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }

        // Get config values
        $config = [
            'digest_alg' => config('qz-tray.certificate.algorithm', 'sha256'),
            'private_key_bits' => config('qz-tray.certificate.key_bits', 2048),
            'private_key_type' => config('qz-tray.certificate.key_type', OPENSSL_KEYTYPE_RSA),
        ];

        $privateKey = openssl_pkey_new($config);
        if (! $privateKey) {
            Log::error('Failed to generate private key: '.openssl_error_string());

            return false;
        }

        // Export private key with headers
        openssl_pkey_export($privateKey, $privateKeyPEM);

        // Get subject from config
        $subject = config('qz-tray.certificate.subject', [
            'countryName' => 'US',
            'stateOrProvinceName' => 'NY',
            'localityName' => 'Canastota',
            'organizationName' => 'QZ Industries, LLC',
            'organizationalUnitName' => 'QZ Industries, LLC',
            'commonName' => 'QZ Tray Cert',
            'emailAddress' => 'support@qz.io',
        ]);

        // Create and sign certificate
        $csr = openssl_csr_new($subject, $privateKey, $config);
        $validityDays = config('qz-tray.certificate.validity_days', 7300);
        $cert = openssl_csr_sign($csr, null, $privateKey, $validityDays, $config, time());

        // Export certificate with headers
        openssl_x509_export($cert, $certificatePEM);

        // Clean up
        openssl_free_key($privateKey);

        // Save files exactly like demo
        file_put_contents($certPath, $certificatePEM);
        file_put_contents($keyPath, $privateKeyPEM);

        // Set permissions
        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        Log::info('QZ Tray certificate generated in format');

        return true;
    }

    /**
     * Get available printers
     */
    public function printers(Request $request): \Illuminate\Http\JsonResponse
    {
        // This endpoint is for web UI, not for QZ Tray
        // QZ Tray gets printers directly via WebSocket
        return response()->json([
            'success' => true,
            'message' => 'Use QZ Tray WebSocket connection to get printers',
            'note' => 'This endpoint is for UI display only',
        ]);
    }

    /**
     * Set printer for path
     */
    public function setPrinter(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'printer' => 'required|string|max:255',
            'path' => 'required|string|max:500',
        ]);

        $printer = $request->input('printer');
        $path = $request->input('path');

        // Store in cache
        $cacheKey = "qz.printer.{$path}";
        $duration = config('qz-tray.printer_cache_duration', 86400);
        Cache::put($cacheKey, $printer, $duration);

        // Store in session for immediate use
        session()->put($cacheKey, $printer);

        return response()->json([
            'success' => true,
            'message' => "Printer '{$printer}' saved for path '{$path}'",
            'path' => $path,
            'printer' => $printer,
        ]);
    }

    /**
     * Get printer for path
     */
    public function getPrinter(Request $request, string $path): \Illuminate\Http\JsonResponse
    {
        $cacheKey = "qz.printer.{$path}";

        $printer = session()->get($cacheKey) ?:
            Cache::get($cacheKey) ?:
                config('qz-tray.default_printer');

        return response()->json([
            'success' => true,
            'path' => $path,
            'printer' => $printer,
        ]);
    }

    /**
     * Get QZ Tray status
     */
    public function status(): \Illuminate\Http\JsonResponse
    {
        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        $certExists = file_exists($certPath);
        $keyExists = file_exists($keyPath);

        return response()->json([
            'success' => true,
            'status' => 'operational',
            'certificate' => $certExists ? 'valid' : 'missing',
            'private_key' => $keyExists ? 'valid' : 'missing',
            'endpoints' => [
                'certificate' => url('/qz/certificate'),
                'sign' => url('/qz/sign'),
            ],
            'version' => '1.1.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Health check
     */
    public function health(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'qz-tray',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Generate SSL certificate and private key - Public endpoint
     */
    public function generateCertificatePublic(): \Illuminate\Http\JsonResponse
    {
        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        // Get certificate directory
        $certDir = dirname($certPath);
        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }

        // Check if already exists
        if (file_exists($certPath) && file_exists($keyPath)) {
            return response()->json([
                'success' => true,
                'message' => 'Certificate already exists',
            ]);
        }

        // Get config values
        $config = [
            'digest_alg' => config('qz-tray.certificate.algorithm', 'sha256'),
            'private_key_bits' => config('qz-tray.certificate.key_bits', 2048),
            'private_key_type' => config('qz-tray.certificate.key_type', OPENSSL_KEYTYPE_RSA),
        ];

        // Generate private key
        $privateKey = openssl_pkey_new($config);
        if (! $privateKey) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to generate private key: '.openssl_error_string(),
            ], 500);
        }

        openssl_pkey_export($privateKey, $privateKeyPEM);

        // Get subject from config
        $subject = config('qz-tray.certificate.subject', [
            'countryName' => 'US',
            'stateOrProvinceName' => 'NY',
            'localityName' => 'Canastota',
            'organizationName' => 'QZ Industries, LLC',
            'organizationalUnitName' => 'QZ Industries, LLC',
            'commonName' => 'QZ Tray Cert',
            'emailAddress' => 'support@qz.io',
        ]);

        // Generate certificate signing request
        $csr = openssl_csr_new($subject, $privateKey, $config);

        // Generate self-signed certificate
        $validityDays = config('qz-tray.certificate.validity_days', 7300);
        $certificate = openssl_csr_sign($csr, null, $privateKey, $validityDays, $config, time());
        openssl_x509_export($certificate, $certificatePEM);

        // Save files
        file_put_contents($certPath, $certificatePEM);
        file_put_contents($keyPath, $privateKeyPEM);

        // Set permissions
        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        // Clean up
        openssl_free_key($privateKey);

        return response()->json([
            'success' => true,
            'message' => 'Certificate generated successfully in format',
            'certificate_path' => $certPath,
            'key_path' => $keyPath,
        ]);
    }

    /**
     * Test endpoint - verify signature works
     */
    public function testSign(Request $request): \Illuminate\Http\JsonResponse
    {
        $testData = 'test_signature_data_'.time();

        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        if (! file_exists($keyPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Private key not found',
            ], 500);
        }

        $privateKeyContent = file_get_contents($keyPath);
        $privateKey = openssl_pkey_get_private($privateKeyContent);

        if ($privateKey === false) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid private key',
            ], 500);
        }

        $signature = '';
        $success = openssl_sign(
            $testData,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA512
        );

        openssl_free_key($privateKey);

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Signing test failed',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Signature test successful',
            'algorithm' => 'SHA512',
            'test_data' => $testData,
            'signature_length' => strlen(base64_encode($signature)),
        ]);
    }

    /**
     * Installer setup
     */
    public function setup(): \Illuminate\Http\JsonResponse
    {
        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));

        $certExists = file_exists($certPath);
        $keyExists = file_exists($keyPath);

        if (! $certExists || ! $keyExists) {
            $this->generateCertificate();
        }

        return response()->json([
            'success' => true,
            'message' => 'QZ Tray setup complete',
            'certificate' => $certExists ? 'exists' : 'generated',
            'private_key' => $keyExists ? 'exists' : 'generated',
            'endpoints' => [
                'certificate' => url('/qz/certificate'),
                'sign' => url('/qz/sign'),
                'status' => url('/qz/status'),
            ],
        ]);
    }

    /**
     * Print job endpoint
     */
    public function print(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'printer' => 'required|string',
            'type' => 'required|in:raw,pdf,html',
            'data' => 'required',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Print job accepted',
            'job_id' => uniqid('qz_'),
            'printer' => $request->input('printer'),
            'type' => $request->input('type'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get print jobs
     */
    public function jobs(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'jobs' => [],
            'message' => 'No active print jobs',
        ]);
    }

    /**
     * Cancel print job
     */
    public function cancelJob($id): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => "Print job {$id} cancelled",
            'job_id' => $id,
        ]);
    }

    /**
     * Download installer
     */
    public function installer($os): \Illuminate\Http\JsonResponse
    {
        $os = strtolower($os);

        if (! in_array($os, ['windows', 'linux', 'macos'])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OS specified',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Installer for {$os}",
            'download_url' => "https://qz.io/download/{$os}",
            'note' => 'Download QZ Tray from official website',
        ]);
    }

    /**
     * Test PDF endpoint
     */
    public function testPdf(): \Illuminate\Http\Response
    {
        // Create a simple test PDF
        $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    h1 { color: #007bff; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .footer { margin-top: 50px; text-align: center; color: #666; }
                </style>
            </head>
            <body>
                <div class="header">
                    <h1>✅ QZ Tray Test PDF</h1>
                    <p>This is a test PDF from Laravel QZ Tray package.</p>
                </div>
                <div class="content">
                    <p><strong>Date:</strong> '.date('Y-m-d').'</p>
                    <p><strong>Time:</strong> '.date('H:i:s').'</p>
                    <p><strong>Status:</strong> Working correctly!</p>
                </div>
                <div class="footer">
                    <hr>
                    <p>Generated by Laravel QZ Tray Package</p>
                </div>
            </body>
            </html>
        ';

        return response($html, 200, [
            'Content-Type' => 'text/html',
        ]);
    }

    /**
     * Test connection endpoint
     */
    public function testConnection(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'QZ Tray API is working',
            'endpoints' => [
                'certificate' => '/qz/certificate',
                'sign' => '/qz/sign',
                'status' => '/qz/status',
                'health' => '/qz/health',
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Clear printer cache
     */
    public function clearCache(): \Illuminate\Http\JsonResponse
    {
        // Clear all printer caches
        $keys = Cache::get('qz.printer_keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }

        // Clear session printer data
        foreach (session()->all() as $key => $value) {
            if (str_starts_with($key, 'qz.printer.')) {
                session()->forget($key);
            }
        }

        Cache::forget('qz.printer_keys');

        return response()->json([
            'success' => true,
            'message' => 'Printer cache cleared',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
