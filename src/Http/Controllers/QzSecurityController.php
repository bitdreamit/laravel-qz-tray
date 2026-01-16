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
     * Get QZ Tray certificate
     */
    public function certificate(): Response
    {
        $certPath = config('qz-tray.cert_path');

        if (! file_exists($certPath)) {
            return response('Certificate not found', 404);
        }

        $cert = file_get_contents($certPath);

        return response($cert, 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'inline; filename="qz-tray-cert.pem"',
            'Cache-Control' => 'public, max-age='.config('qz-tray.cert_ttl', 3600),
        ]);
    }

    /**
     * Sign data for QZ Tray
     */
    public function sign(Request $request): Response
    {
        $request->validate([
            'data' => 'required|string',
        ]);

        $data = $request->input('data');
        $keyPath = config('qz-tray.key_path');

        if (! file_exists($keyPath)) {
            return response('Private key not found', 500);
        }

        $privateKey = file_get_contents($keyPath);

        openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Get available printers
     */
    public function printers(Request $request): \Illuminate\Http\JsonResponse
    {
        $cacheKey = 'qz.printers.list';

        if (Cache::has($cacheKey)) {
            return response()->json([
                'success' => true,
                'printers' => Cache::get($cacheKey),
                'cached' => true,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        // Placeholder printers
        $printers = [
            ['name' => 'Receipt Printer', 'type' => 'thermal', 'default' => true],
            ['name' => 'Label Printer', 'type' => 'zpl', 'default' => false],
            ['name' => 'Invoice Printer', 'type' => 'laser', 'default' => false],
            ['name' => 'Shipping Label', 'type' => 'zpl', 'default' => false],
        ];

        Cache::put($cacheKey, $printers, now()->addMinutes(5));

        return response()->json([
            'success' => true,
            'printers' => $printers,
            'cached' => false,
            'timestamp' => now()->toIso8601String(),
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
        Cache::put($cacheKey, $printer, config('qz-tray.printer_cache_duration'));

        // Store in session for immediate use
        session()->put($cacheKey, $printer);

        // If user is authenticated, store in database
        if ($user = $request->user()) {
            $user->settings()->updateOrCreate(
                ['key' => "qz_printer_{$path}"],
                ['value' => $printer]
            );
        }

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
     * Print document
     */
    public function print(Request $request): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
            'printer' => 'nullable|string',
            'type' => 'in:pdf,raw,zpl,escpos',
            'copies' => 'integer|min:1|max:10',
        ]);

        $url = $request->input('url');
        $type = $request->input('type', 'pdf');
        $copies = $request->input('copies', 1);

        // Get printer for current path
        $path = $request->input('path', $request->path());
        $printer = $request->input('printer') ?:
            $this->getPrinterForPath($path);

        // Generate job ID
        $jobId = 'qz_'.uniqid().'_'.time();

        // Log print job
        if (config('qz-tray.logging.enabled')) {
            Log::channel(config('qz-tray.logging.channel'))->info('Print job created', [
                'job_id' => $jobId,
                'url' => $url,
                'printer' => $printer,
                'type' => $type,
                'copies' => $copies,
                'user_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Print job created',
            'job_id' => $jobId,
            'printer' => $printer,
            'type' => $type,
            'copies' => $copies,
            'queued_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get print jobs
     */
    public function jobs(Request $request): \Illuminate\Http\JsonResponse
    {
        $jobs = Cache::get('qz.print.jobs', []);

        return response()->json([
            'success' => true,
            'jobs' => $jobs,
            'total' => count($jobs),
        ]);
    }

    /**
     * Cancel print job
     */
    public function cancelJob(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $jobs = Cache::get('qz.print.jobs', []);

        if (isset($jobs[$id])) {
            unset($jobs[$id]);
            Cache::put('qz.print.jobs', $jobs, now()->addHour());

            return response()->json([
                'success' => true,
                'message' => "Job {$id} cancelled",
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => "Job {$id} not found",
        ], 404);
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
            'certificate' => $certExists ? 'valid' : 'missing',
            'private_key' => $keyExists ? 'valid' : 'missing',
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
            'timestamp' => now()->toIso8601String(),
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 .' MB',
        ]);
    }

    /**
     * Download installer
     */
    public function installer(Request $request, string $os): \Illuminate\Http\Response
    {
        $installers = config('qz-tray.installers', [
            'windows' => 'qz-tray-windows.exe',
            'linux' => 'qz-tray-linux.deb',
            'macos' => 'qz-tray-macos.pkg',
        ]);

        if (! isset($installers[$os])) {
            abort(404, 'Installer not found for this OS');
        }

        $filename = $installers[$os];
        $path = public_path("vendor/qz-tray/installers/{$filename}");

        if (! file_exists($path)) {
            abort(404, 'Installer file not found');
        }

        return response()->file($path, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Get printer for specific path
     */
    private function getPrinterForPath(string $path): ?string
    {
        $cacheKey = "qz.printer.{$path}";

        // Check session first
        if ($printer = session()->get($cacheKey)) {
            return $printer;
        }

        // Check cache
        if ($printer = Cache::get($cacheKey)) {
            return $printer;
        }

        // Check for wildcard match
        $paths = Cache::get('qz.printer.paths', []);
        foreach ($paths as $cachedPath => $cachedPrinter) {
            if (str_ends_with($cachedPath, '/*')) {
                $basePath = substr($cachedPath, 0, -2);
                if (str_starts_with($path, $basePath)) {
                    return $cachedPrinter;
                }
            }
        }

        // Return default
        return config('qz-tray.default_printer');
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
