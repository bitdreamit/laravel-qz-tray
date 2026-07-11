<?php

namespace Bitdreamit\QzTray\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class QzSecurityController extends Controller
{
    public function index()
    {
        return view('qz-tray::default');
    }

    public function smart()
    {
        return view('qz-tray::smart');
    }

    public function certificate(): \Illuminate\Http\Response
    {
        $certPath = config('qz-tray.cert_path');

        if (! $certPath || ! file_exists($certPath)) {
            abort(404, 'Certificate not found. Run: php artisan qz:generate-certificate');
        }

        // Respect cert_ttl config (seconds the browser may cache the cert).
        // Falls back to 0 (no caching) when not configured.
        $ttl = (int) config('qz-tray.cert_ttl', 0);

        return response(
            file_get_contents($certPath),
            200,
            [
                'Content-Type'  => 'text/plain',
                'Cache-Control' => $ttl > 0
                    ? 'public, max-age=' . $ttl
                    : 'no-store, no-cache, must-revalidate',
                'Pragma'        => 'no-cache',
            ]
        );
    }

    public function sign(Request $request): \Illuminate\Http\Response
    {
        $data = $request->input('data');

        if (! is_string($data) || $data === '') {
            abort(400, 'Missing or invalid data parameter');
        }

        $keyPath = config('qz-tray.key_path');

        if (! $keyPath || ! file_exists($keyPath)) {
            abort(500, 'Private key missing. Run: php artisan qz:generate-certificate');
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));

        if (! $privateKey) {
            abort(500, 'Invalid private key. Run: php artisan qz:generate-certificate --force');
        }

        $signature = null;
        $signed = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        if (PHP_VERSION_ID < 80000) {
            openssl_free_key($privateKey);
        }

        if (! $signed || $signature === null) {
            abort(500, 'Failed to sign data');
        }

        return response(base64_encode($signature), 200, ['Content-Type' => 'text/plain']);
    }

    public function status(): \Illuminate\Http\JsonResponse
    {
        $certPath = config('qz-tray.cert_path');
        $keyPath  = config('qz-tray.key_path');
        $certExists = $certPath && file_exists($certPath);
        $keyExists  = $keyPath  && file_exists($keyPath);
        $prefix = config('qz-tray.routes.prefix', 'qz');

        return response()->json([
            'success'     => true,
            'status'      => ($certExists && $keyExists) ? 'operational' : 'degraded',
            'certificate' => $certExists ? 'present' : 'missing',
            'private_key' => $keyExists  ? 'present' : 'missing',
            'endpoints'   => [
                'certificate' => url("/{$prefix}/certificate"),
                'sign'        => url("/{$prefix}/sign"),
            ],
            'version'   => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function health(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status'    => 'healthy',
            'service'   => 'qz-tray',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function setPrinter(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'printer' => 'required|string|max:255',
            'path'    => 'required|string|max:500',
        ]);

        $safePath = preg_replace('/[^a-zA-Z0-9\-_\/]/', '_', $validated['path']);
        $key = 'qz.printer.' . $safePath;
        $ttl = config('qz-tray.printer_cache_duration', 86400);

        Cache::put($key, $validated['printer'], $ttl);

        // Track keys so qz:clear-cache can find all of them
        $keys = Cache::get('qz.printer_keys', []);
        if (! in_array($key, $keys)) {
            $keys[] = $key;
            Cache::put('qz.printer_keys', $keys, $ttl);
        }

        session()->put($key, $validated['printer']);

        return response()->json([
            'success' => true,
            'printer' => $validated['printer'],
            'path'    => $validated['path'],
        ]);
    }

    public function getPrinter(string $path): \Illuminate\Http\JsonResponse
    {
        $safePath = preg_replace('/[^a-zA-Z0-9\-_\/]/', '_', $path);
        $key = 'qz.printer.' . $safePath;

        $printer = session()->get($key)
            ?? Cache::get($key)
            ?? config('qz-tray.default_printer');

        return response()->json([
            'success' => true,
            'printer' => $printer,
            'path'    => $path,
        ]);
    }

    public function clearCache(): \Illuminate\Http\JsonResponse
    {
        foreach (session()->all() as $key => $value) {
            if (str_starts_with($key, 'qz.printer.')) {
                session()->forget($key);
            }
        }

        $keys = Cache::get('qz.printer_keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget('qz.printer_keys');

        return response()->json([
            'success'   => true,
            'message'   => 'Printer cache cleared',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function printers(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Use QZ Tray WebSocket connection to get printers',
            'note'    => 'This endpoint is UI / status only',
        ]);
    }

    public function print(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'printer'    => 'required|string|max:255',
            'type'       => 'required|in:raw,pdf,html,zpl,escpos',
            'data'       => 'required_without:url|nullable|string',
            'url'        => 'required_without:data|nullable|string|max:2048',
            'copies'     => 'nullable|integer|min:1|max:999',
            'document'   => 'nullable|string|max:255',
            'metadata'   => 'nullable|array',
        ]);

        $jobId = uniqid('qz_', true);
        $type  = $request->input('type');

        // Persist to database when the qz_print_jobs table exists.
        // This makes the migration that ships with the package actually useful.
        $dbLogged = false;
        if (\Illuminate\Support\Facades\Schema::hasTable('qz_print_jobs')) {
            try {
                $user = $request->user();
                \DB::table('qz_print_jobs')->insert([
                    'tenant_id'     => null,
                    'user_id'       => $user?->getAuthIdentifier(),
                    'user_type'     => $user ? get_class($user) : null,
                    'printer_name'  => $request->input('printer'),
                    'document_url'  => $request->input('url', ''),
                    'document_type' => $type,
                    'copies'        => (int) $request->input('copies', 1),
                    'status'        => 'pending',
                    'metadata'      => json_encode($request->input('metadata', [])),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $dbLogged = true;
            } catch (\Throwable $e) {
                Log::warning('[QZ Tray] Could not persist print job to DB: ' . $e->getMessage());
            }
        }

        if (config('qz-tray.logging.enabled', false)) {
            Log::channel(config('qz-tray.logging.channel', 'stack'))
                ->info('[QZ Tray] Print job received', [
                    'job_id'  => $jobId,
                    'printer' => $request->input('printer'),
                    'type'    => $type,
                    'db'      => $dbLogged,
                ]);
        }

        return response()->json([
            'success'   => true,
            'message'   => 'Print job accepted',
            'job_id'    => $jobId,
            'printer'   => $request->input('printer'),
            'type'      => $type,
            'db_logged' => $dbLogged,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function jobs(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'jobs'    => [],
            'message' => 'No active print jobs',
        ]);
    }

    public function cancelJob($id): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => "Print job {$id} cancelled",
            'job_id'  => $id,
        ]);
    }

    public function installer(string $os): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $os      = strtolower($os);
        $allowed = ['windows', 'linux', 'macos'];

        if (! in_array($os, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Invalid OS specified'], 400);
        }

        $fileName   = config("qz-tray.installers.{$os}");
        $publicPath = public_path("vendor/qz-tray/installers/{$fileName}");

        // Serve the bundled installer when it was published and exists.
        if ($fileName && is_file($publicPath)) {
            $mime = [
                'windows' => 'application/vnd.microsoft.portable-executable',
                'linux'   => 'application/vnd.debian.binary-package',
                'macos'   => 'application/vnd.apple.installer+xml',
            ][$os] ?? 'application/octet-stream';

            return response()->download($publicPath, $fileName, [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]);
        }

        // Fallback: return JSON pointing to the official download page.
        return response()->json([
            'success'      => true,
            'message'      => "Installer info for {$os}",
            'download_url' => 'https://qz.io/download',
            'note'         => 'Bundled installer not published. Run: php artisan vendor:publish --tag=qz-installers',
        ]);
    }

    /**
     * Test PDF endpoint — no external dependency required.
     * If barryvdh/laravel-dompdf is installed it will produce a real PDF.
     */
    public function testPdf(): Response
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">
            <title>QZ Tray Test PDF</title>
            <style>body{font-family:sans-serif;margin:40px;}h1{color:#333;}</style>
        </head><body>
            <h1>QZ Tray Test Document</h1>
            <p>This is a test document generated by Laravel QZ Tray.</p>
            <p>Generated: ' . now()->toDateTimeString() . '</p>
        </body></html>';

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->stream('qz-test.pdf');
        }

        // Fallback: return HTML so the browser can render / print it
        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function testConnection(): \Illuminate\Http\JsonResponse
    {
        $prefix = config('qz-tray.routes.prefix', 'qz');

        return response()->json([
            'success'   => true,
            'message'   => 'QZ Tray API is working',
            'endpoints' => [
                'certificate' => "/{$prefix}/certificate",
                'sign'        => "/{$prefix}/sign",
                'status'      => "/{$prefix}/status",
                'health'      => "/{$prefix}/health",
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function setup(): \Illuminate\Http\JsonResponse
    {
        $certPath = config('qz-tray.cert_path');
        $keyPath  = config('qz-tray.key_path');
        $prefix   = config('qz-tray.routes.prefix', 'qz');

        return response()->json([
            'success'     => true,
            'certificate' => ($certPath && file_exists($certPath)) ? 'exists' : 'missing',
            'private_key' => ($keyPath  && file_exists($keyPath))  ? 'exists' : 'missing',
            'endpoints'   => [
                'certificate' => url("/{$prefix}/certificate"),
                'sign'        => url("/{$prefix}/sign"),
                'status'      => url("/{$prefix}/status"),
            ],
        ]);
    }

    /**
     * Generate certificate via HTTP (disabled by default for security).
     * Enable with: 'allow_public_cert_generate' => true in config.
     */
    public function generateCertificatePublic(): \Illuminate\Http\JsonResponse
    {
        if (! config('qz-tray.allow_public_cert_generate', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Public certificate generation is disabled. Use: php artisan qz:generate-certificate',
            ], 403);
        }

        if (! extension_loaded('openssl')) {
            return response()->json(['success' => false, 'message' => 'OpenSSL extension not available'], 500);
        }

        $certPath   = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath    = config('qz-tray.key_path',  storage_path('qz/private-key.pem'));
        $certConfig = config('qz-tray.certificate', []);

        $opensslConfig = [
            'digest_alg'       => $certConfig['algorithm'] ?? 'sha256',
            'private_key_bits' => $certConfig['key_bits']  ?? 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $subject = $certConfig['subject'] ?? [
            'countryName'      => 'US',
            'organizationName' => 'QZ Tray',
            'commonName'       => 'QZ Tray Certificate',
        ];

        $privateKey = openssl_pkey_new($opensslConfig);
        if (! $privateKey) {
            return response()->json(['success' => false, 'message' => 'Failed to generate private key'], 500);
        }

        openssl_pkey_export($privateKey, $privateKeyPem);
        $csr = openssl_csr_new($subject, $privateKey, $opensslConfig);
        if (! $csr) {
            return response()->json(['success' => false, 'message' => 'Failed to create CSR'], 500);
        }
        $cert = openssl_csr_sign($csr, null, $privateKey, $certConfig['validity_days'] ?? 7300, $opensslConfig, time());

        if (! $cert) {
            return response()->json(['success' => false, 'message' => 'Failed to create certificate'], 500);
        }

        openssl_x509_export($cert, $certificatePem);

        $dir = dirname($certPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($certPath, $certificatePem);
        file_put_contents($keyPath,  $privateKeyPem);
        chmod($certPath, 0644);
        chmod($keyPath,  0600);

        return response()->json([
            'success'   => true,
            'message'   => 'Certificate generated successfully',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Test-sign endpoint — verifies signing pipeline end-to-end.
     */
    public function testSign(): \Illuminate\Http\JsonResponse
    {
        $keyPath = config('qz-tray.key_path');

        if (! $keyPath || ! file_exists($keyPath)) {
            return response()->json(['success' => false, 'message' => 'Private key missing'], 500);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));
        if (! $privateKey) {
            return response()->json(['success' => false, 'message' => 'Invalid private key'], 500);
        }

        $testData  = 'qz_test_' . time();
        $signature = null;
        $ok        = openssl_sign($testData, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response()->json([
            'success'   => $ok,
            'message'   => $ok ? 'Signing works correctly' : 'Signing failed',
            'algorithm' => 'SHA512',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
