<?php

namespace Bitdreamit\QzTray\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Bitdreamit\QzTray\Events\PrintJobLogged;
use Bitdreamit\QzTray\Events\PrintJobStatusUpdated;
use Bitdreamit\QzTray\QzTrayServiceProvider;
use Bitdreamit\QzTray\Support\CertKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /**
     * GET /qz/setup — the browser-facing Client Setup Wizard.
     * (POST /qz/setup below keeps returning the original JSON status.)
     *
     * Walks the operator through QZ Tray installation + all three trust
     * decisions (transport TLS, signing cert, Chrome LNA) with copy-paste
     * commands, live connection probing and fingerprint comparison — the
     * exact friction that made repeated "Allow" prompts feel unfixable.
     */
    public function wizard()
    {
        $prefix = config('qz-tray.routes.prefix', 'qz');
        $certPath = (string) (config('qz-tray.cert_path') ?: storage_path('qz/digital-certificate.txt'));

        $fingerprint = null;
        $subjectCn = null;
        $mode = 'self-signed';
        $daysLeft = null;

        if (is_file($certPath) && extension_loaded('openssl')) {
            try {
                $pem = (string) file_get_contents($certPath);
                $fingerprint = CertKit::fingerprintSha1Pretty($pem);
                $parsed = openssl_x509_parse($pem);
                $subjectCn = $parsed['subject']['CN'] ?? null;
                $daysLeft = CertKit::daysUntilExpiry($pem);
                $trust = $this->trustSummary([
                    'self_signed' => isset($parsed['subject'], $parsed['issuer']) ? $parsed['subject'] === $parsed['issuer'] : null,
                ]);
                $mode = $trust['mode'];
            } catch (\Throwable $e) {
                // leave defaults; wizard degrades gracefully
            }
        }

        return view('qz-tray::wizard', [
            'fingerprint' => $fingerprint,
            'subjectCn' => $subjectCn,
            'mode' => $mode,
            'daysLeft' => $daysLeft,
            'prefix' => $prefix,
            'statusUrl' => url("/{$prefix}/status"),
            'certUrl' => url("/{$prefix}/certificate"),
            'caCertUrl' => url("/{$prefix}/ca-certificate"),
            'bundleUrl' => url("/{$prefix}/client-bundle"),
            'installerUrl' => url("/{$prefix}/installer/windows"),
        ]);
    }

    public function certificate(): Response
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
                'Content-Type' => 'text/plain',
                'Cache-Control' => $ttl > 0
                    ? 'public, max-age='.$ttl
                    : 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
            ]
        );
    }

    /**
     * GET /qz/ca-certificate — download the trust root clients deploy as QZ
     * Tray's override.crt (the local Root CA when present, otherwise the
     * self-signed site certificate). Public certificate material — contains
     * NO secrets. Gated by qz-tray.routes.serve_ca (disable when the bundle
     * is distributed out-of-band via GPO/Intune instead).
     */
    public function caCertificate(): Response
    {
        if (! config('qz-tray.routes.serve_ca', true)) {
            abort(404);
        }

        $caCertPath = (string) (config('qz-tray.certificate.ca.cert_path') ?: storage_path('qz/ca/qz-root-ca.crt'));
        $siteCertPath = (string) (config('qz-tray.cert_path') ?: storage_path('qz/digital-certificate.txt'));

        $path = is_file($caCertPath) ? $caCertPath : $siteCertPath;

        if (! is_file($path)) {
            abort(404, 'Trust root not found. Run: php artisan qz:generate-ca && php artisan qz:generate-certificate --ca');
        }

        $filename = is_file($caCertPath) ? 'qz-root-ca.crt' : 'override.crt';

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /qz/client-bundle — download the ready-made Windows trust bundle
     * (override.crt + qz-client-setup.ps1 + setup.bat + provision.json +
     * README). Built on first request via the same code path as
     * `php artisan qz:client-bundle`. v1.4.2: works on hosts without
     * ext-zip too — the build command falls back to a pure-PHP zip writer.
     */
    public function clientBundle(): BinaryFileResponse
    {
        if (! config('qz-tray.routes.serve_bundle', true)) {
            abort(404);
        }

        $bundleDir = storage_path('qz/client-bundle');
        $zipPath = $bundleDir.'/qz-client-bundle.zip';
        $stampPath = $bundleDir.'/.built-stamp';

        // Rebuild when missing or when the signing cert changed since build.
        $certPath = (string) (config('qz-tray.cert_path') ?: storage_path('qz/digital-certificate.txt'));
        $certStamp = is_file($certPath) ? md5_file($certPath) : 'missing';
        $needsBuild = ! is_file($zipPath) || ! is_file($stampPath) || file_get_contents($stampPath) !== $certStamp;

        if ($needsBuild) {
            try {
                \Artisan::call('qz:client-bundle', ['--force' => true, '--zip' => true]);
            } catch (\Throwable $e) {
                report($e);
                // v1.4.1: fall back to the previously built bundle instead of
                // a hard 500. The trust root (CA) does not change on leaf
                // rotation, so a stale zip remains valid for client trust.
                // Only abort when nothing was ever built.
                if (! is_file($zipPath)) {
                    abort(500, 'Client bundle rebuild failed: '.$e->getMessage()
                        .' — run: php artisan qz:client-bundle --zip');
                }
            }
        }

        if (! is_file($zipPath)) {
            abort(500, 'Client bundle could not be built. Run: php artisan qz:client-bundle --zip');
        }

        // v1.4.2: serve as a BinaryFileResponse with every output buffer
        // discarded first. Stray bytes that used to ride along in the
        // streamed response — a UTF-8 BOM from an included file, a PHP
        // deprecation notice, Laravel Debugbar output — are enough to make
        // Windows declare the archive "invalid" even when the zip on disk
        // was perfectly fine. BinaryFileResponse also adds Content-Length,
        // which lets browsers fail loudly instead of saving truncated data.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        return response()->download($zipPath, 'qz-client-bundle.zip', [
            'Cache-Control' => 'no-store',
        ]);
    }

    public function sign(Request $request): Response
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

    public function status(): JsonResponse
    {
        $certPath = config('qz-tray.cert_path');
        $keyPath = config('qz-tray.key_path');
        $certExists = $certPath && file_exists($certPath);
        $keyExists = $keyPath && file_exists($keyPath);
        $prefix = config('qz-tray.routes.prefix', 'qz');

        // v1.3.0: expose the certificate fingerprint + issuer so operators can
        // verify that every subdomain of a project presents the SAME keypair.
        // QZ Tray's trust prompt is keyed to the fingerprint — identical
        // fingerprints across subdomains means clients only trust once.
        $certDetails = null;
        if ($certExists) {
            $parsed = @openssl_x509_parse((string) file_get_contents($certPath));
            if (is_array($parsed)) {
                $sha1 = openssl_x509_fingerprint((string) file_get_contents($certPath), 'sha1');
                $certDetails = [
                    'subject_cn' => $parsed['subject']['CN'] ?? null,
                    'organization' => $parsed['subject']['O'] ?? null,
                    'issuer_cn' => $parsed['issuer']['CN'] ?? null,
                    'self_signed' => (isset($parsed['subject'], $parsed['issuer'])) ? $parsed['subject'] === $parsed['issuer'] : null,
                    'fingerprint_sha1' => $sha1 ? implode(':', str_split($sha1, 2)) : null,
                    'valid_to' => isset($parsed['validTo_time_t']) ? date('c', $parsed['validTo_time_t']) : null,
                    'shared_path' => $certPath !== storage_path('qz/digital-certificate.txt'),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'status' => ($certExists && $keyExists) ? 'operational' : 'degraded',
            'certificate' => $certExists ? 'present' : 'missing',
            'private_key' => $keyExists ? 'present' : 'missing',
            'certificate_details' => $certDetails,
            'trust' => $this->trustSummary($certDetails),
            'endpoints' => [
                'certificate' => url("/{$prefix}/certificate"),
                'sign' => url("/{$prefix}/sign"),
                'ca_certificate' => url("/{$prefix}/ca-certificate"),
                'client_bundle' => url("/{$prefix}/client-bundle"),
                'setup_wizard' => url("/{$prefix}/setup"),
            ],
            'version' => QzTrayServiceProvider::VERSION, // was hardcoded '1.0.0' (AUDIT M1)
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Summarise the current zero-prompt trust posture so the setup wizard,
     * /qz/status consumers and docs never disagree about which path applies.
     */
    protected function trustSummary(?array $certDetails): array
    {
        $caCertPath = (string) (config('qz-tray.certificate.ca.cert_path') ?: storage_path('qz/ca/qz-root-ca.crt'));
        $certPath = (string) (config('qz-tray.cert_path') ?: storage_path('qz/digital-certificate.txt'));
        $hasCa = is_file($caCertPath);
        $leafChainsCa = false;

        if ($hasCa && is_file($certPath)) {
            try {
                $leafChainsCa = CertKit::leafChainsTo((string) file_get_contents($certPath), (string) file_get_contents($caCertPath));
            } catch (\Throwable $e) {
                $leafChainsCa = false;
            }
        }

        $selfSigned = $certDetails['self_signed'] ?? null;

        // 'public-ca'  → real CA cert (Let's Encrypt/AutoSSL/Cloudflare edge)
        //                QZ Tray trusts it silently, zero client changes.
        // 'own-ca'     → leaf chains to our Root CA → deploy override.crt once.
        // 'self-signed'→ Always Allow once per machine (or deploy the leaf
        //                itself as override.crt).
        $mode = 'self-signed';
        if ($selfSigned === false && ! $leafChainsCa) {
            $mode = 'public-ca';
        } elseif ($hasCa && $leafChainsCa) {
            $mode = 'own-ca';
        }

        return [
            'mode' => $mode,
            'root_ca_present' => $hasCa,
            'leaf_chains_to_root_ca' => $leafChainsCa,
            'ca_certificate_url' => url('/'.config('qz-tray.routes.prefix', 'qz').'/ca-certificate'),
            'client_bundle_url' => url('/'.config('qz-tray.routes.prefix', 'qz').'/client-bundle'),
            'guide' => 'docs/zero-prompt.md',
        ];
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'qz-tray',
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
    /**
     * v7 when config('qz-tray.uuid_version') allows it AND the running
     * Laravel actually has Str::uuid7() (native since Laravel 11 — this
     * package also supports 10.x, which doesn't have it), falling back to
     * the classic v4 on any failure. Feature-detected via method_exists()
     * rather than a Laravel version check, since what matters is whether
     * the method is callable, not which version string is reported (a
     * pinned older ramsey/uuid on an otherwise-11.x app would fail the
     * same way).
     */
    private function generateUuid(): string
    {
        if (config('qz-tray.uuid_version', 'v7') === 'v7'
            && method_exists(Str::class, 'uuid7')) {
            try {
                return (string) Str::uuid7();
            } catch (\Throwable $e) {
                // Fall through to v4 below — e.g. an incompatible
                // ramsey/uuid version present despite the method existing.
            }
        }

        return (string) Str::uuid();
    }

    /**
     * v1.2.0: tenant_id (and the user morph columns) are now natively typed
     * — uuid or unsignedBigInteger — matching config('qz-tray.id_type'),
     * not a flexible string accepting either shape. So validation must be
     * strict against whichever type is actually configured: a bigint-typed
     * column will reject a uuid string just as hard as a real uuid column
     * rejects a numeric one. This intentionally does NOT accept "either
     * shape" anymore — that permissiveness only made sense when the
     * column itself was an untyped string.
     */
    private function isValidTenantId(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true; // nullable — handled by the 'nullable' rule, not here
        }

        if (config('qz-tray.id_type', 'uuid') === 'uuid') {
            return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
        }

        // AUDIT M4: bound numeric ids to the unsigned BIGINT range. The old
        // bare '^\d+$' accepted '99999999999999999999' (21 digits), which
        // sailed through validation and then exploded inside the INSERT with
        // an out-of-range error — turning a clean 422 into a 500 on
        // setPrinter(). 18446744073709551615 is PHP_INT_MAX * 2 + 1.
        if (! preg_match('/^\d{1,20}$/', $value)) {
            return false;
        }

        return strlen($value) < 20
            || (strlen($value) === 20 && strcmp($value, '18446744073709551615') <= 0);
    }

    /**
     * Resolves tenant_id/project_id the same way for every endpoint:
     * explicit request value (either param name) wins, then the optional
     * `qz-tray.tenant_id_resolver` config callback, else null. Centralizes
     * what print() and jobs() previously duplicated inline, and is now also
     * used by setPrinter/getPrinter/clearCache (BUG recommendation #4 —
     * qz_printer_preferences is tenant-scoped too, not just qz_print_jobs).
     *
     * Returns null when there's genuinely no tenant or the value doesn't
     * match config('qz-tray.id_type')'s shape — both tenant_id columns are
     * nullable native types (uuid or unsignedBigInteger) as of v1.2.0, so
     * null is always the correct "no tenant" value on either column type.
     */
    private function resolveTenantId(Request $request): ?string
    {
        $tenantId = $request->input('tenant_id') ?? $request->input('project_id');

        if ($tenantId === null && is_callable(config('qz-tray.tenant_id_resolver'))) {
            $tenantId = call_user_func(config('qz-tray.tenant_id_resolver'), $request);
        }

        if ($tenantId === null || ! $this->isValidTenantId((string) $tenantId)) {
            return null;
        }

        return (string) $tenantId;
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

    public function setPrinter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'printer' => 'required|string|max:255',
            'path' => 'required|string|max:500',
            'device_id' => 'nullable|uuid',
            'tenant_id' => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! $this->isValidTenantId($value)) {
                    $fail("The {$attribute} must be either an integer id or a UUID.");
                }
            }],
            'project_id' => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! $this->isValidTenantId($value)) {
                    $fail("The {$attribute} must be either an integer id or a UUID.");
                }
            }],
        ]);

        // AUDIT H4: print()/jobs()/cancelJob() degraded gracefully when the
        // package migrations had not been run, but setPrinter() hit the
        // missing table directly and surfaced a raw QueryException 500.
        if (! Schema::hasTable('qz_printer_preferences')) {
            return response()->json([
                'success' => false,
                'message' => 'qz_printer_preferences table not migrated. Run: php artisan migrate',
            ], 503);
        }

        $identities = $this->resolveIdentities($request);
        $tenantId = $this->resolveTenantId($request);

        if (empty($identities)) {
            return response()->json([
                'success' => false,
                'message' => 'No identity (user, device, or session) available to scope this preference to.',
            ], 422);
        }

        foreach ($identities as $type => $value) {
            \DB::table('qz_printer_preferences')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'identity_type' => $type,
                    'identity_value' => $value,
                    'path' => $validated['path'],
                ],
                ['printer_name' => $validated['printer'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return response()->json([
            'success' => true,
            'printer' => $validated['printer'],
            'path' => $validated['path'],
            'scoped_to' => array_keys($identities),
            'tenant_id' => $tenantId,
        ]);
    }

    public function getPrinter(Request $request, string $path): JsonResponse
    {
        // AUDIT H4: consistent graceful degradation (see setPrinter()).
        if (! Schema::hasTable('qz_printer_preferences')) {
            return response()->json([
                'success' => false,
                'message' => 'qz_printer_preferences table not migrated. Run: php artisan migrate',
            ], 503);
        }

        $identities = $this->resolveIdentities($request);
        $tenantId = $this->resolveTenantId($request);
        $priority = config('qz-tray.identity_priority', ['device', 'user', 'session']);

        $printer = null;
        $matchedType = null;

        foreach ($priority as $type) {
            if (! isset($identities[$type])) {
                continue;
            }

            $row = \DB::table('qz_printer_preferences')
                ->where('tenant_id', $tenantId)
                ->where('identity_type', $type)
                ->where('identity_value', $identities[$type])
                ->where('path', $path)
                ->first();

            if ($row) {
                $printer = $row->printer_name;
                $matchedType = $type;
                break;
            }
        }

        return response()->json([
            'success' => true,
            'printer' => $printer ?? config('qz-tray.default_printer'),
            'path' => $path,
            'scoped_to' => $matchedType, // null when falling back to the global default
        ]);
    }

    /**
     * AUDIT H3: query-param twin of getPrinter(). The segment route
     * (/qz/printer/{path}) sends the page path URL-encoded, and Apache
     * rejects encoded slashes by default (AllowEncodedSlashes Off -> 404).
     * This variant carries the same value as ?path= which no web server
     * mangles. Same behavior, same response shape.
     */
    public function getPrinterByQuery(Request $request): JsonResponse
    {
        $path = (string) $request->query('path', '');

        if ($path === '' || mb_strlen($path) > 500) {
            return response()->json(['success' => false, 'message' => 'Missing or invalid path parameter'], 400);
        }

        return $this->getPrinter($request, $path);
    }

    public function clearCache(Request $request): JsonResponse
    {
        // AUDIT H4: consistent graceful degradation (see setPrinter()).
        if (! Schema::hasTable('qz_printer_preferences')) {
            return response()->json([
                'success' => false,
                'message' => 'qz_printer_preferences table not migrated. Run: php artisan migrate',
            ], 503);
        }

        $identities = $this->resolveIdentities($request);

        // Unlike setPrinter/getPrinter, an explicit tenant is optional here:
        // if the caller passes one, only that tenant's rows for this
        // identity are cleared; if not, this identity's stored printer is
        // wiped across every tenant it has a row in — the more useful
        // default for "reset this workstation" style calls, since a caller
        // clearing cache generally doesn't know (or care) which tenants a
        // shared device has previously printed for.
        $explicitTenant = $request->input('tenant_id') ?? $request->input('project_id');

        $deleted = 0;
        foreach ($identities as $type => $value) {
            $query = \DB::table('qz_printer_preferences')
                ->where('identity_type', $type)
                ->where('identity_value', $value);

            if ($explicitTenant !== null) {
                if ($tid = $this->resolveTenantId($request)) {
                    $query->where('tenant_id', $tid);
                }
            }

            $deleted += $query->delete();
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
            'success' => true,
            'message' => "Printer cache cleared ({$deleted} preference rows removed)",
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function printers(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Use QZ Tray WebSocket connection to get printers',
            'note' => 'This endpoint is UI / status only',
        ]);
    }

    public function print(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'printer' => 'required|string|max:255',
            'type' => 'required|in:raw,pdf,html,zpl,escpos',
            'data' => 'required_without:url|nullable|string',
            'url' => 'required_without:data|nullable|string|max:2048',
            'copies' => 'nullable|integer|min:1|max:999',
            'document' => 'nullable|string|max:255',
            'device_id' => 'nullable|uuid',
            'job_id' => 'nullable|uuid',
            // AUDIT C2: the client can now drive the job lifecycle explicitly
            // (pending -> processing -> completed/failed) through the same
            // idempotent POST /qz/print endpoint, or via PATCH /qz/jobs/{id}.
            'status' => 'nullable|in:pending,processing,completed,failed,cancelled',
            'error_message' => 'nullable|string|max:1000',
            'metadata' => 'nullable|array',
            // Accepted under either name: some host apps call it
            // "tenant_id", others "project_id" — same value, one column.
            'tenant_id' => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! $this->isValidTenantId($value)) {
                    $fail("The {$attribute} must be either an integer id or a UUID.");
                }
            }],
            'project_id' => ['nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (! $this->isValidTenantId($value)) {
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
        $usesUuid = config('qz-tray.id_type', 'uuid') === 'uuid';
        $clientJobId = $request->input('job_id');
        $jobId = ($usesUuid && $clientJobId)
            ? $clientJobId
            // Not collision-safe uniqid() (used pre-1.1) — generateUuid()
            // (v7 when available, v4 fallback) is. Also serves as the
            // pre-insert placeholder in bigint mode, for the
            // (db_logged === false) response path below.
            : $this->generateUuid();
        $type = $request->input('type');

        // AUDIT H5: the X-Device-Id header used to be written straight into
        // the uuid-typed device_id column without any validation — any
        // non-UUID garbage made the INSERT throw and silently degraded
        // db_logged to false with a warning on every print. Validate the
        // header exactly like resolveIdentities() does, falling back to the
        // already-validated body value, else null.
        $deviceId = $request->header('X-Device-Id');

        if ($deviceId !== null
            && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
            $deviceId = $validated['device_id'] ?? null;
        }

        // Project/tenant id: explicit request value wins, validated against
        // whichever type config('qz-tray.id_type') is currently set to
        // (bigint or uuid — matches tenant_id's actual column type, see the
        // migration). If the host app didn't send one, fall back to an
        // optional app-supplied resolver — useful for multi-tenant apps
        // (e.g. stancl/tenancy) that want every print job auto-tagged with
        // the current tenant without every call site having to pass it
        // explicitly.
        $tenantId = $this->resolveTenantId($request);

        // Persist to database when the qz_print_jobs table exists.
        // This makes the migration that ships with the package actually useful.
        //
        // AUDIT C2: this used to be a blind INSERT. The SmartPrint client
        // logs the SAME job more than once by design (a "completed" report
        // follows the initial log, with the same job_id), so every successful
        // print either crashed into a duplicate-primary-key error (uuid
        // mode, leaving the row stuck at 'pending' forever) or created a
        // SECOND row (bigint mode, duplicating history). The endpoint is now
        // an UPSERT: an existing row for the same client job id is updated
        // in place (status moved forward, metadata refreshed), otherwise a
        // new row is created. The additive client_job_id column (v1.2.1
        // migration) gives bigint installs the same idempotency.
        $dbLogged = false;
        $status = $validated['status'] ?? null;

        if (Schema::hasTable('qz_print_jobs')) {
            try {
                $user = $request->user();
                $base = [
                    'tenant_id' => $tenantId,
                    'user_id' => $user ? (string) $user->getAuthIdentifier() : null,
                    'user_type' => $user ? get_class($user) : null,
                    'device_id' => $deviceId,
                    'printer_name' => $request->input('printer'),
                    'document_url' => $request->input('url', ''),
                    'document_type' => $type,
                    'copies' => (int) $request->input('copies', 1),
                    'metadata' => json_encode($request->input('metadata', [])),
                    'error_message' => $validated['error_message'] ?? null,
                    'updated_at' => now(),
                ];

                $existing = null;

                if ($clientJobId) {
                    $existing = $usesUuid
                        ? \DB::table('qz_print_jobs')->where('id', $clientJobId)->first()
                        : \DB::table('qz_print_jobs')->where('client_job_id', $clientJobId)->first();
                }

                if ($existing) {
                    // Same job reported again — update in place. processed_at
                    // is stamped the first time the job reaches a terminal
                    // state (completed/failed/cancelled) and never moved back.
                    \DB::table('qz_print_jobs')->where('id', $existing->id)->update($base + [
                        'status' => $status ?? $existing->status,
                        'processed_at' => in_array($status, ['completed', 'failed', 'cancelled'], true)
                            ? ($existing->processed_at ?? now())
                            : $existing->processed_at,
                    ]);
                    $jobId = (string) $existing->id;
                } else {
                    $row = $base + ['status' => $status ?? 'pending', 'created_at' => now()];

                    if ($clientJobId) {
                        $row['client_job_id'] = $clientJobId;
                    }

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
                }
                $dbLogged = true;
            } catch (\Throwable $e) {
                Log::warning('[QZ Tray] Could not persist print job to DB: '.$e->getMessage());
            }
        }

        event(new PrintJobLogged(
            $jobId,
            $request->input('printer'),
            $type,
            $status ?? 'pending',
            $dbLogged
        ));

        if (config('qz-tray.logging.enabled', false)) {
            Log::channel(config('qz-tray.logging.channel', 'stack'))
                ->info('[QZ Tray] Print job received', [
                    'job_id' => $jobId,
                    'printer' => $request->input('printer'),
                    'type' => $type,
                    'db' => $dbLogged,
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Print job accepted',
            'job_id' => $jobId,
            'printer' => $request->input('printer'),
            'type' => $type,
            'db_logged' => $dbLogged,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * AUDIT C2 (client half): a dedicated status-update endpoint.
     * The SmartPrint client marks a job processing/completed/failed via
     * PATCH instead of replaying POST /qz/print. POST /qz/print remains an
     * upsert so both old and new clients keep working. Ownership scoping is
     * identical to cancelJob() — only the submitting user/device may mutate
     * a job, and foreign ids return 404 without leaking existence.
     */
    public function updateJobStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,completed,failed,cancelled',
            'error_message' => 'nullable|string|max:1000',
            'device_id' => 'nullable|uuid',
        ]);

        if (! Schema::hasTable('qz_print_jobs')) {
            return response()->json(['success' => false, 'message' => 'qz_print_jobs table not migrated'], 404);
        }

        $user = $request->user();
        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');

        if ($deviceId !== null
            && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
            $deviceId = null;
        }

        $updated = \DB::table('qz_print_jobs')
            ->where('id', $id)
            ->where(function ($query) use ($user, $deviceId) {
                if ($user) {
                    $query->where(function ($q) use ($user) {
                        $q->where('user_id', (string) $user->getAuthIdentifier())
                            ->where('user_type', get_class($user));
                    });
                }

                if ($deviceId) {
                    $query->orWhere('device_id', $deviceId);
                }

                if (! $user && ! $deviceId) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->update([
                'status' => $validated['status'],
                'error_message' => $validated['error_message'] ?? null,
                'processed_at' => in_array($validated['status'], ['completed', 'failed', 'cancelled'], true)
                    ? now()
                    : null,
                'updated_at' => now(),
            ]);

        if (! $updated) {
            return response()->json(['success' => false, 'message' => "Print job {$id} not found or not owned by you"], 404);
        }

        event(new PrintJobStatusUpdated($id, $validated['status']));

        return response()->json([
            'success' => true,
            'message' => "Print job {$id} marked as {$validated['status']}",
            'job_id' => $id,
            'status' => $validated['status'],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        if (! Schema::hasTable('qz_print_jobs')) {
            return response()->json(['success' => true, 'jobs' => [], 'message' => 'qz_print_jobs table not migrated']);
        }

        // AUDIT C2 companion: ?status= lets the client filter the queue
        // (comma-separated; unknown values are ignored). Defaults stay
        // exactly as before (pending + processing) for backward compat.
        $statuses = collect(explode(',', (string) $request->query('status', '')))
            ->map(fn ($s) => trim($s))
            ->filter(fn ($s) => in_array($s, ['pending', 'processing', 'completed', 'failed', 'cancelled'], true))
            ->unique()
            ->values()
            ->all();

        $query = \DB::table('qz_print_jobs')
            ->whereIn('status', $statuses ?: ['pending', 'processing'])
            ->orderBy('created_at');

        // Scope the queue to the requesting identity so PC-1's queue view
        // never shows PC-2's jobs (or vice versa) when several workstations
        // share the same Laravel session/auth guard.
        $user = $request->user();
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
        $tenantId = $this->resolveTenantId($request);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $jobs = $query->limit(100)->get(['id', 'printer_name', 'document_type', 'status', 'copies', 'created_at']);

        return response()->json(['success' => true, 'jobs' => $jobs]);
    }

    public function cancelJob(Request $request, string $id): JsonResponse
    {
        if (! Schema::hasTable('qz_print_jobs')) {
            return response()->json(['success' => false, 'message' => 'qz_print_jobs table not migrated'], 404);
        }

        // AUDIT C4 (IDOR): anyone with a session used to be able to cancel
        // ANY job by id — and in bigint mode those ids are sequential and
        // guessable. Scope the lookup to identities owned by the requester
        // (same scoping as jobs()): matching user (id+type pair) or the
        // device UUID. A job owned by somebody else returns 404 without
        // leaking its existence.
        $user = $request->user();
        $deviceId = $request->header('X-Device-Id') ?? $request->input('device_id');

        if ($deviceId !== null
            && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
            $deviceId = null;
        }

        $job = \DB::table('qz_print_jobs')
            ->where('id', $id)
            ->where(function ($query) use ($user, $deviceId) {
                if ($user) {
                    $query->where(function ($q) use ($user) {
                        $q->where('user_id', (string) $user->getAuthIdentifier())
                            ->where('user_type', get_class($user));
                    });
                }

                if ($deviceId) {
                    $query->orWhere('device_id', $deviceId);
                }

                if (! $user && ! $deviceId) {
                    // No identity at all -> owns nothing.
                    $query->whereRaw('1 = 0');
                }
            })
            ->first();

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
            'status' => 'cancelled',
            'processed_at' => now(),
            'updated_at' => now(),
        ]);

        event(new PrintJobStatusUpdated($id, 'cancelled'));

        return response()->json([
            'success' => true,
            'message' => "Print job {$id} cancelled",
            'job_id' => $id,
        ]);
    }

    public function installer(string $os): BinaryFileResponse|JsonResponse
    {
        $os = strtolower($os);
        $allowed = ['windows', 'linux', 'macos'];

        if (! in_array($os, $allowed)) {
            return response()->json(['success' => false, 'message' => 'Invalid OS specified'], 400);
        }

        $fileName = config("qz-tray.installers.{$os}");
        $publicPath = public_path("vendor/qz-tray/installers/{$fileName}");

        // AUDIT H1: the repo previously tracked 0-BYTE placeholder installer
        // files; is_file() passed and users downloaded an empty .exe/.deb/.pkg
        // that silently corrupted their install. Require a real payload and
        // fall through to the official-download JSON otherwise.
        if ($fileName && is_file($publicPath) && filesize($publicPath) > 0) {
            $mime = [
                'windows' => 'application/vnd.microsoft.portable-executable',
                'linux' => 'application/vnd.debian.binary-package',
                'macos' => 'application/vnd.apple.installer+xml',
            ][$os] ?? 'application/octet-stream';

            return response()->download($publicPath, $fileName, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            ]);
        }

        // Fallback: return JSON pointing to the official download page.
        return response()->json([
            'success' => true,
            'message' => "Installer info for {$os}",
            'download_url' => 'https://qz.io/download',
            'note' => 'Bundled installer not published. Run: php artisan vendor:publish --tag=qz-installers',
        ]);
    }

    /**
     * Test PDF endpoint — no external dependency required.
     * If barryvdh/laravel-dompdf is installed it will produce a real PDF.
     *
     * AUDIT M2: the declared return type used to be \Illuminate\Http\Response,
     * but DomPDF's stream() returns Symfony\Component\HttpFoundation\StreamedResponse
     * — a SIBLING of Illuminate\Http\Response, not a subclass — so the
     * endpoint threw a TypeError on every call whenever DomPDF was installed.
     * Widening to the Symfony parent type covers both return paths.
     */
    public function testPdf(): \Symfony\Component\HttpFoundation\Response
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">
            <title>QZ Tray Test PDF</title>
            <style>body{font-family:sans-serif;margin:40px;}h1{color:#333;}</style>
        </head><body>
            <h1>QZ Tray Test Document</h1>
            <p>This is a test document generated by Laravel QZ Tray.</p>
            <p>Generated: '.now()->toDateTimeString().'</p>
        </body></html>';

        if (class_exists(Pdf::class)) {
            return Pdf::loadHTML($html)->stream('qz-test.pdf');
        }

        // Fallback: return HTML so the browser can render / print it
        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    public function testConnection(): JsonResponse
    {
        $prefix = config('qz-tray.routes.prefix', 'qz');

        return response()->json([
            'success' => true,
            'message' => 'QZ Tray API is working',
            'endpoints' => [
                'certificate' => "/{$prefix}/certificate",
                'sign' => "/{$prefix}/sign",
                'status' => "/{$prefix}/status",
                'health' => "/{$prefix}/health",
            ],
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function setup(): JsonResponse
    {
        $certPath = config('qz-tray.cert_path');
        $keyPath = config('qz-tray.key_path');
        $prefix = config('qz-tray.routes.prefix', 'qz');

        return response()->json([
            'success' => true,
            'certificate' => ($certPath && file_exists($certPath)) ? 'exists' : 'missing',
            'private_key' => ($keyPath && file_exists($keyPath)) ? 'exists' : 'missing',
            'trust' => $this->trustSummary(null),
            'endpoints' => [
                'certificate' => url("/{$prefix}/certificate"),
                'sign' => url("/{$prefix}/sign"),
                'status' => url("/{$prefix}/status"),
                'ca_certificate' => url("/{$prefix}/ca-certificate"),
                'client_bundle' => url("/{$prefix}/client-bundle"),
            ],
        ]);
    }

    /**
     * Generate certificate via HTTP (disabled by default for security).
     * Enable with: 'allow_public_cert_generate' => true in config.
     */
    public function generateCertificatePublic(): JsonResponse
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

        $certPath = config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt'));
        $keyPath = config('qz-tray.key_path', storage_path('qz/private-key.pem'));
        $certConfig = config('qz-tray.certificate', []);

        $opensslConfig = [
            'digest_alg' => $certConfig['algorithm'] ?? 'sha256',
            'private_key_bits' => $certConfig['key_bits'] ?? 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $subject = $certConfig['subject'] ?? [
            'countryName' => 'US',
            'organizationName' => 'QZ Tray',
            'commonName' => 'QZ Tray Certificate',
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
        file_put_contents($keyPath, $privateKeyPem);
        chmod($certPath, 0644);
        chmod($keyPath, 0600);

        return response()->json([
            'success' => true,
            'message' => 'Certificate generated successfully',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Test-sign endpoint — verifies signing pipeline end-to-end.
     */
    public function testSign(): JsonResponse
    {
        $keyPath = config('qz-tray.key_path');

        if (! $keyPath || ! file_exists($keyPath)) {
            return response()->json(['success' => false, 'message' => 'Private key missing'], 500);
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($keyPath));
        if (! $privateKey) {
            return response()->json(['success' => false, 'message' => 'Invalid private key'], 500);
        }

        $testData = 'qz_test_'.time();
        $signature = null;
        $ok = openssl_sign($testData, $signature, $privateKey, OPENSSL_ALGO_SHA512);

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Signing works correctly' : 'Signing failed',
            'algorithm' => 'SHA512',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
