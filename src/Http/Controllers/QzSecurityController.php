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
     * Serve public certificate
     */
    public function certificate(): Response
    {
        $certPath = config('qz-tray.cert_path', storage_path('app/qz/certificate.txt'));

        if (! is_readable($certPath)) {
            // Generate certificate if not exists
            $this->generateCertificate();
        }

        if (! is_readable($certPath)) {
            return response('Certificate not found', 404);
        }

        $certificate = file_get_contents($certPath);

        return response(
            $certificate,
            200,
            [
                'Content-Type' => 'text/plain',
                'Content-Length' => strlen($certificate),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    }

    /**
     * Sign data for QZ Tray
     *
     * IMPORTANT: Must return RAW base64 string
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

        $keyPath = config('qz-tray.key_path', storage_path('app/qz/private-key.pem'));

        if (! is_readable($keyPath)) {
            Log::error('QZ Tray private key not found at: '.$keyPath);

            return response('Private key not found', 500);
        }

        $privateKeyContent = file_get_contents($keyPath);
        $privateKey = openssl_pkey_get_private($privateKeyContent);

        if ($privateKey === false) {
            Log::error('QZ Tray invalid private key format');

            return response('Invalid private key format', 500);
        }

        $signature = '';

        // CRITICAL FIX: Use OPENSSL_ALGO_SHA512 (not SHA256)
        $success = openssl_sign(
            $data,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA512  // CHANGED FROM SHA256
        );

        openssl_free_key($privateKey);

        if (! $success) {
            Log::error('QZ Tray signing failed for data: '.substr($data, 0, 50));

            return response('Signing failed', 500);
        }

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
        Cache::put($cacheKey, $printer, config('qz-tray.printer_cache_duration', 3600));

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
        $certPath = config('qz-tray.cert_path', storage_path('app/qz/certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('app/qz/private-key.pem'));

        $certExists = is_readable($certPath);
        $keyExists = is_readable($keyPath);

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
     * Generate SSL certificate and private key
     */
    public function generateCertificate()
    {
        $certDir = dirname(config('qz-tray.cert_path', storage_path('app/qz/certificate.txt')));

        if (! is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }

        $certPath = $certDir.'/certificate.txt';
        $keyPath = $certDir.'/private-key.pem';

        // Check if already exists
        if (file_exists($certPath) && file_exists($keyPath)) {
            return response()->json([
                'success' => true,
                'message' => 'Certificate already exists',
            ]);
        }

        // Generate certificate using OpenSSL command line
        // This is more reliable than PHP's openssl functions
        $config = [
            'digest_alg' => 'sha512',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Generate private key
        $privateKey = openssl_pkey_new($config);
        openssl_pkey_export($privateKey, $privateKeyPEM);

        // Generate certificate signing request
        $csr = openssl_csr_new([
            'commonName' => config('app.name', 'Laravel App').' QZ Tray',
            'countryName' => 'US',
            'organizationName' => config('app.name', 'Laravel App'),
        ], $privateKey, $config);

        // Generate self-signed certificate
        $certificate = openssl_csr_sign($csr, null, $privateKey, 365, $config, time());
        openssl_x509_export($certificate, $certificatePEM);

        // Save files
        file_put_contents($certPath, $certificatePEM);
        file_put_contents($keyPath, $privateKeyPEM);

        // Set permissions
        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        return response()->json([
            'success' => true,
            'message' => 'Certificate generated successfully',
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

        $keyPath = config('qz-tray.key_path', storage_path('app/qz/private-key.pem'));

        if (! is_readable($keyPath)) {
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
        $certPath = config('qz-tray.cert_path', storage_path('app/qz/certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('app/qz/private-key.pem'));

        $certExists = is_readable($certPath);
        $keyExists = is_readable($keyPath);

        if (! $certExists || ! $keyExists) {
            $result = $this->generateCertificate();
            if ($result instanceof \Illuminate\Http\JsonResponse) {
                return $result;
            }
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
}
