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

    /**
     * Resolve every identity applicable to the current request, in the
     * order defined by `qz-tray.identity_priority` (default: device, user,
     * session). A request can legitimately match more than one identity —
     * e.g. a logged-in user on a lab workstation that also sends a device
     * UUID — in which case `setPrinter` writes a row for every identity
     * present (so it stays correct however priority is configured), and
     * `getPrinter` reads the first configured priority that has a stored
     * row.
     *
     * IMPORTANT: unlike the old implementation, there is no global,
     * identity-less fallback key. A path with no matching identity row
     * always falls through to `config('qz-tray.default_printer')`, never
     * to some other user's/device's last selection.
     */
    /**
     * True if $value is either an unsigned integer (bigint-keyed project
     * table) or a UUID (uuid-keyed project table). Used to validate
     * tenant_id/project_id without hardcoding a single PK type — this
     * package is installed across multiple client projects that don't all
     * key their "project"/"tenant" table the same way.
     */
    private function isBigintOrUuid(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true; // nullable — handled by the 'nullable' rule, not here
        }

        return (bool) preg_match('/^\d+$/', $value)
            || (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    private function resolveIdentities(Request $request): array
    {
        $identities = [];

        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
        if ($deviceId && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
            $identities['device'] = $deviceId;
        }

        $user = $request->user();
        if ($user) {
            $identities['user'] = (string) $user->getAuthIdentifier();
        }

        // Session is always available under the `web` middleware and acts
        // as the final, still-isolated fallback for anonymous requests that
        // didn't send a device UUID (e.g. an older client build).
        if ($request->hasSession()) {
            $identities['session'] = $request->session()->getId();
        }

        return $identities;
    }

    public function setPrinter(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'printer'   => 'required|string|max:255',
            'path'      => 'required|string|max:500',
            'device_id' => 'nullable|uuid',
        ]);

        $identities = $this->resolveIdentities($request);

        if (empty($identities)) {
            return response()->json([
                'success' => false,
                'message' => 'No identity (user, device, or session) available to scope this preference to.',
            ], 422);
        }

        foreach ($identities as $type => $value) {
            \DB::table('qz_printer_preferences')->updateOrInsert(
                ['identity_type' => $type, 'identity_value' => $value, 'path' => $validated['path']],
                ['printer_name' => $validated['printer'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json([
            'success'    => true,
            'printer'    => $validated['printer'],
            'path'       => $validated['path'],
            'scoped_to'  => array_keys($identities),
        ]);
    }

    public function getPrinter(Request $request, string $path): \Illuminate\Http\JsonResponse
    {
        $identities = $this->resolveIdentities($request);
        $priority   = config('qz-tray.identity_priority', ['device', 'user', 'session']);

        $printer = null;
        $matchedType = null;

        foreach ($priority as $type) {
            if (! isset($identities[$type])) {
                continue;
            }

            $row = \DB::table('qz_printer_preferences')
                ->where('identity_type', $type)
                ->where('identity_value', $identities[$type])
                ->where('path', $path)
                ->first();

            if ($row) {
                $printer     = $row->printer_name;
                $matchedType = $type;
                break;
            }
        }

        return response()->json([
            'success'    => true,
            'printer'    => $printer ?? config('qz-tray.default_printer'),
            'path'       => $path,
            'scoped_to'  => $matchedType, // null when falling back to the global default
        ]);
    }

    public function clearCache(Request $request): \Illuminate\Http\JsonResponse
    {
        $identities = $this->resolveIdentities($request);

        $deleted = 0;
        foreach ($identities as $type => $value) {
            $deleted += \DB::table('qz_printer_preferences')
                ->where('identity_type', $type)
                ->where('identity_value', $value)
                ->delete();
        }

        // Legacy Cache/session keys from pre-1.1 installs, cleaned up best-effort.
        foreach (session()->all() as $key => $value) {
            if (str_starts_with($key, 'qz.printer.')) {
                session()->forget($key);
            }
        }
        $legacyKeys = Cache::get('qz.printer_keys', []);
        foreach ($legacyKeys as $key) {
            Cache::forget($key);
        }
        Cache::forget('qz.printer_keys');

        return response()->json([
            'success'   => true,
            'message'   => "Printer cache cleared ({$deleted} preference rows removed)",
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
            'device_id'  => 'nullable|uuid',
            'job_id'     => 'nullable|uuid',
            'metadata'   => 'nullable|array',
            // Accepted under either name: some host apps call it
            // "tenant_id", others "project_id" — same value, one column.
            'tenant_id'  => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! $this->isBigintOrUuid($value)) {
                    $fail("The {$attribute} must be either an integer id or a UUID.");
                }
            }],
            'project_id' => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! $this->isBigintOrUuid($value)) {
                    $fail("The {$attribute} must be either an integer id or a UUID.");
                }
            }],
        ]);

        // v1.1.1: the primary key IS the job identifier now (no separate
        // `id` (bigint) + `uuid` (string) pair — see the migration). Which
        // type it is was fixed at migrate-time by config('qz-tray.id_type'):
        //
        //   uuid mode   — the client-generated id (smart-print.js mints one
        //                 per job via crypto.randomUUID() and sends it as
        //                 `job_id`) IS what gets written to `id`, so the id
        //                 returned to the browser always matches the row —
        //                 no lookup/translation step needed.
        //   bigint mode — a client-supplied job_id can't become the PK, so
        //                 the row is inserted without one and the
        //                 database-assigned auto-increment value becomes
        //                 $jobId instead, once the insert below completes.
        $usesUuid   = config('qz-tray.id_type', 'uuid') === 'uuid';
        $clientJobId = $request->input('job_id');
        $jobId = ($usesUuid && $clientJobId)
            ? $clientJobId
            // Not collision-safe uniqid() (used pre-1.1) — Str::uuid()
            // (uuid4, via ramsey/uuid, already a Laravel dependency) is.
            // Also serves as the pre-insert placeholder in bigint mode,
            // for the (db_logged === false) response path below.
            : (string) \Illuminate\Support\Str::uuid();
        $type  = $request->input('type');
        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');

        // Project/tenant id: explicit request value wins (bigint OR uuid,
        // whichever the host app's project model uses — see the migration
        // comment on the `tenant_id` column). If the host app didn't send
        // one, fall back to an optional app-supplied resolver — useful for
        // multi-tenant apps (e.g. stancl/tenancy) that want every print job
        // auto-tagged with the current tenant without every call site
        // having to pass it explicitly.
        $tenantId = $request->input('tenant_id') ?? $request->input('project_id');
        if ($tenantId === null && is_callable(config('qz-tray.tenant_id_resolver'))) {
            $tenantId = call_user_func(config('qz-tray.tenant_id_resolver'), $request);
        }

        // Persist to database when the qz_print_jobs table exists.
        // This makes the migration that ships with the package actually useful.
        $dbLogged = false;
        if (\Illuminate\Support\Facades\Schema::hasTable('qz_print_jobs')) {
            try {
                $user = $request->user();
                $row = [
                    'tenant_id'     => $tenantId,
                    'user_id'       => $user?->getAuthIdentifier(),
                    'user_type'     => $user ? get_class($user) : null,
                    'device_id'     => $deviceId,
                    'printer_name'  => $request->input('printer'),
                    'document_url'  => $request->input('url', ''),
                    'document_type' => $type,
                    'copies'        => (int) $request->input('copies', 1),
                    'status'        => 'pending',
                    'metadata'      => json_encode($request->input('metadata', [])),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];

                if ($usesUuid) {
                    $row['id'] = $jobId;
                    \DB::table('qz_print_jobs')->insert($row);
                } else {
                    // Auto-increment PK: the id can only be known after
                    // insert. Overwrites the placeholder uuid above with
                    // the real row id so the response's job_id actually
                    // matches what jobs()/cancelJob() can look up.
                    $jobId = (string) \DB::table('qz_print_jobs')->insertGetId($row);
                }
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

    public function jobs(Request $request): \Illuminate\Http\JsonResponse
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('qz_print_jobs')) {
            return response()->json(['success' => true, 'jobs' => [], 'message' => 'qz_print_jobs table not migrated']);
        }

        $query = \DB::table('qz_print_jobs')
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('created_at');

        // Scope the queue to the requesting identity so PC-1's queue view
        // never shows PC-2's jobs (or vice versa) when several workstations
        // share the same Laravel session/auth guard.
        $user     = $request->user();
        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');
        if ($user) {
            $query->where('user_id', (string) $user->getAuthIdentifier())->where('user_type', get_class($user));
        } elseif ($deviceId) {
            $query->where('device_id', $deviceId);
        }

        // Additive: when a tenant_id/project_id is supplied (explicitly or
        // via the resolver), narrow further to that project — matters when
        // a shared device/user identity is reused across more than one
        // project's data within the same host app.
        $tenantId = $request->input('tenant_id') ?? $request->input('project_id');
        if ($tenantId === null && is_callable(config('qz-tray.tenant_id_resolver'))) {
            $tenantId = call_user_func(config('qz-tray.tenant_id_resolver'), $request);
        }
        if ($tenantId !== null && $this->isBigintOrUuid((string) $tenantId)) {
            $query->where('tenant_id', (string) $tenantId);
        }

        $jobs = $query->limit(100)->get(['id', 'printer_name', 'document_type', 'status', 'copies', 'created_at']);

        return response()->json(['success' => true, 'jobs' => $jobs]);
    }

    public function cancelJob(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('qz_print_jobs')) {
            return response()->json(['success' => false, 'message' => 'qz_print_jobs table not migrated'], 404);
        }

        $job = \DB::table('qz_print_jobs')->where('id', $id)->first();

        if (! $job) {
            return response()->json(['success' => false, 'message' => "Print job {$id} not found"], 404);
        }

        if (! in_array($job->status, ['pending', 'processing'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Print job {$id} is already {$job->status} and cannot be cancelled",
            ], 409);
        }

        \DB::table('qz_print_jobs')->where('id', $id)->update([
            'status'       => 'cancelled',
            'processed_at' => now(),
            'updated_at'   => now(),
        ]);

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
