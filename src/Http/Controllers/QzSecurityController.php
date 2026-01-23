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
     * Show QZ Tray test page
     */
    public function index()
    {
        return view('qz-tray::test');
    }

    /**
     * Serve the public certificate for QZ Tray.
     * ONLY the certificate (not private key) should be exposed.
     */
    public function certificate(): Response
    {
        $certPath = config('qz-tray.cert_path');

        if (! file_exists($certPath)) {
            Log::error('[QZ Tray] Certificate not found at '.$certPath);

            return response('Certificate not found', 404);
        }

        $certificate = file_get_contents($certPath);

        return response($certificate, 200, [
            'Content-Type' => 'text/plain',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Sign data for QZ Tray requests.
     */
    public function sign(Request $request): Response
    {
        $data = $request->input('data');

        if (! $data) {
            return response('Missing data parameter', 400);
        }

        $keyPath = config('qz-tray.key_path');

        if (! file_exists($keyPath)) {
            Log::critical('[QZ Tray] Private key missing');

            return response('Signing unavailable', 500);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));

        if (! $privateKey) {
            Log::critical('[QZ Tray] Invalid private key');

            return response('Signing unavailable', 500);
        }

        $signature = '';
        $success = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        openssl_free_key($privateKey);

        if (! $success) {
            Log::error('[QZ Tray] Signing failed');

            return response('Signing failed', 500);
        }

        return response(base64_encode($signature), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Get QZ Tray status
     */
    public function status(): \Illuminate\Http\JsonResponse
    {
        $certExists = file_exists(config('qz-tray.cert_path'));
        $keyExists = file_exists(config('qz-tray.key_path'));

        return response()->json([
            'success' => true,
            'status' => 'operational',
            'certificate' => $certExists ? 'present' : 'missing',
            'private_key' => $keyExists ? 'present' : 'missing',
            'endpoints' => [
                'certificate' => url('/qz/certificate'),
                'sign' => url('/qz/sign'),
            ],
            'version' => '1.0.0',
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
     * Set printer for a specific path
     */
    public function setPrinter(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'printer' => 'required|string|max:255',
            'path' => 'required|string|max:255',
        ]);

        $key = "qz.printer.{$validated['path']}";
        $ttl = config('qz-tray.printer_cache_duration');

        Cache::put($key, $validated['printer'], $ttl);
        session()->put($key, $validated['printer']);

        return response()->json([
            'success' => true,
            'printer' => $validated['printer'],
            'path' => $validated['path'],
        ]);
    }

    /**
     * Get printer for a specific path
     */
    public function getPrinter(string $path): \Illuminate\Http\JsonResponse
    {
        $key = "qz.printer.{$path}";

        return response()->json([
            'success' => true,
            'printer' => session()->get($key) ?? Cache::get($key) ?? config('qz-tray.default_printer'),
            'path' => $path,
        ]);
    }

    /**
     * Clear printer cache
     */
    public function clearCache(): \Illuminate\Http\JsonResponse
    {
        foreach (session()->all() as $key => $value) {
            if (str_starts_with($key, 'qz.printer.')) {
                session()->forget($key);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Printer cache cleared',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Return available printers (UI endpoint only)
     */
    public function printers(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Use QZ Tray WebSocket connection to get printers',
            'note' => 'This endpoint is UI only',
        ]);
    }

    /**
     * Print job endpoint (dummy)
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
     * List active print jobs (dummy)
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
     * Cancel print job (dummy)
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
     * Installer info endpoint
     */
    public function installer(string $os): \Illuminate\Http\JsonResponse
    {
        $os = strtolower($os);
        $allowed = ['windows', 'linux', 'macos'];

        if (! in_array($os, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Invalid OS specified'], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Installer info for {$os}",
            'download_url' => 'https://qz.io/download',
        ]);
    }

    /**
     * Test PDF endpoint
     */
    public function testPdf(): Response
    {
        $html = '<!DOCTYPE html>
            <html><head><style>
                body{font-family:Arial,sans-serif;padding:20px;}
                h1{color:#007bff;}
                .header{text-align:center;margin-bottom:30px;}
                .footer{margin-top:50px;text-align:center;color:#666;}
            </style></head><body>
                <div class="header"><h1>QZ Tray Test PDF</h1></div>
                <p>Date: '.date('Y-m-d').'</p>
                <p>Time: '.date('H:i:s').'</p>
                <p>Status: Operational</p>
                <div class="footer"><hr><p>Generated by Laravel QZ Tray</p></div>
            </body></html>';

        return response($html, 200, ['Content-Type' => 'text/html']);
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
     * Setup endpoint (dummy)
     */
    public function setup(): \Illuminate\Http\JsonResponse
    {
        $certPath = config('qz-tray.cert_path');
        $keyPath = config('qz-tray.key_path');

        return response()->json([
            'success' => true,
            'certificate' => file_exists($certPath) ? 'exists' : 'missing',
            'private_key' => file_exists($keyPath) ? 'exists' : 'missing',
            'endpoints' => [
                'certificate' => url('/qz/certificate'),
                'sign' => url('/qz/sign'),
                'status' => url('/qz/status'),
            ],
        ]);
    }
}
