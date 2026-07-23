<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    */
    'cert_path' => storage_path('qz/digital-certificate.txt'),
    'key_path' => storage_path('qz/private-key.pem'),
    'cert_ttl' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Certificate Generation Settings
    |--------------------------------------------------------------------------
    */
    'certificate' => [
        'validity_days' => 7300,
        'algorithm' => 'sha256',
        'key_bits' => 2048,
        // Use integer 0 (= OPENSSL_KEYTYPE_RSA) so the config can be cached
        // via `php artisan config:cache` even when the openssl extension is
        // not loaded at cache time. OPENSSL_KEYTYPE_RSA === 0.
        'key_type' => 0,
        'subject' => [
            'countryName' => 'BD',
            'stateOrProvinceName' => 'Rangpur',
            'localityName' => 'Rangpur',
            'organizationName' => 'Bit Dream IT',
            'organizationalUnitName' => 'Bit Dream IT',
            'commonName' => 'Laravel QZ Tray',
            'emailAddress' => 'info@bitdreamit.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Generate Certificate on Boot
    |--------------------------------------------------------------------------
    | When true, a certificate is generated on first boot if none exists.
    | Keep false in production; use `php artisan qz:generate-certificate`.
    */
    'auto_generate_cert' => env('QZ_AUTO_GENERATE_CERT', false),

    /*
    |--------------------------------------------------------------------------
    | Allow Public Certificate Generation via HTTP
    |--------------------------------------------------------------------------
    | Disabled by default for security. Only enable in a secured context.
    */
    'allow_public_cert_generate' => env('QZ_ALLOW_PUBLIC_CERT_GENERATE', false),

    /*
    |--------------------------------------------------------------------------
    | Printer Settings
    |--------------------------------------------------------------------------
    */
    'default_printer' => env('QZ_DEFAULT_PRINTER'),
    'allow_printer_switch' => true,
    'remember_printer_per_page' => true,
    'printer_cache_duration' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Printer Memory Identity Priority
    |--------------------------------------------------------------------------
    | When resolving which stored printer to use for a path, the request may
    | match more than one identity (a logged-in user AND a device UUID AND a
    | session). This defines which one wins, checked in order:
    |
    |   'device'  - the workstation/browser (X-Device-Id header). Use this
    |               first for shared kiosks/lab PCs where the physical
    |               machine — not who is logged in — determines the printer
    |               (e.g. a lab PC always prints to its attached label
    |               printer regardless of which technician is logged in).
    |   'user'    - the authenticated user. Put this first for apps where a
    |               person's printer choice should follow them between
    |               machines.
    |   'session' - anonymous fallback, isolated per browser session.
    |
    | Every identity present on a request is still written on setPrinter(),
    | so changing this order later does not lose any previously saved
    | preference.
    */
    'identity_priority' => ['device', 'user', 'session'],

    /*
    |--------------------------------------------------------------------------
    | Tenant / Project ID Resolver
    |--------------------------------------------------------------------------
    | Optional callable(Request $request): string|int|null. Every print job
    | is tagged with a tenant_id/project_id (see the qz_print_jobs.tenant_id
    | column — stored as a string so it works whether the calling project's
    | "project"/"tenant" table uses a bigint or a uuid primary key). Callers
    | can always pass `tenant_id` or `project_id` explicitly in the request;
    | this resolver only fires when neither was sent, e.g. for multi-tenant
    | apps (stancl/tenancy, etc.) that want every job auto-tagged with the
    | current tenant without passing it at every call site:
    |
    |   'tenant_id_resolver' => fn ($request) => tenant('id'),
    |
    | Leave null to require explicit tenant_id/project_id on each request.
    */
    'tenant_id_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Print Job ID Type
    |--------------------------------------------------------------------------
    | v1.1.1+: controls the primary key type of the qz_print_jobs table.
    | Read at migration time — change it BEFORE running
    | `php artisan migrate` for the first time; changing it afterwards has
    | no effect on an already-created table (drop/recreate or write a new
    | migration if you need to convert an existing install).
    |
    |   'uuid'   (default) - id is a uuid. Never a guessable sequential
    |             integer, so it's safe to hand straight back to the client
    |             and is what GET /qz/jobs and DELETE /qz/jobs/{id} use.
    |   'bigint' - id is a normal auto-increment integer. Slightly smaller/
    |             faster index; fine for fully internal/admin-only queues
    |             where exposing a sequential id to the browser isn't a
    |             concern.
    */
    'id_type' => env('QZ_JOB_ID_TYPE', 'uuid'),

    /*
    |--------------------------------------------------------------------------
    | UUID Version (when id_type = 'uuid')
    |--------------------------------------------------------------------------
    | 'v7' (default) - time-ordered (RFC 9562). The first 48 bits are a
    |        millisecond timestamp, so ids sort roughly by creation time —
    |        much better B-tree index locality than v4 for a write-heavy
    |        table like qz_print_jobs (v4 is fully random, so every insert
    |        lands in a random leaf page instead of appending to the end).
    |        Falls back to v4 automatically, per-request, if:
    |          - Str::uuid7() doesn't exist (Laravel 10.x, or ramsey/uuid
    |            older than 4.7 — this package supports Laravel 10.x-12.x,
    |            and 10.x has no native v7 support), or
    |          - generation throws for any other reason.
    |        Both versions fit the same `uuid` column — switching later, or
    |        falling back mid-flight, is not a breaking change.
    | 'v4' - always the classic fully-random UUID (Str::uuid()).
    */
    'uuid_version' => env('QZ_UUID_VERSION', 'v7'),

    /*
    |--------------------------------------------------------------------------
    | WebSocket Settings
    |--------------------------------------------------------------------------
    | Default port is 8181 (QZ Tray default).
    */
    'websocket' => [
        'host' => env('QZ_WEBSOCKET_HOST', 'localhost'),
        'port' => env('QZ_WEBSOCKET_PORT', 8181),
        'retries' => 1,
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser Fallback
    |--------------------------------------------------------------------------
    */
    'fallback' => [
        'enabled' => true,
        'open_in_new_tab' => true,
        'show_warning' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hotkey Settings
    |--------------------------------------------------------------------------
    */
    'hotkey' => [
        'enabled' => true,
        'combination' => 'ctrl+shift+p',
        'require_confirmation' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Settings
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => 'qz',
        'middleware' => ['web'],
        'throttle' => '60,1',
        // API routes (routes/api.php) are disabled by default. Enable only
        // when you need a stateless, sanctum-protected surface.
        'api' => [
            'enabled' => env('QZ_API_ENABLED', false),
            'prefix' => 'api/qz',
            'middleware' => ['auth:sanctum', 'throttle:60,1'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('QZ_LOGGING_ENABLED', false),
        'channel' => env('QZ_LOGGING_CHANNEL', 'stack'),
        'level' => env('QZ_LOGGING_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer Settings
    |--------------------------------------------------------------------------
    */
    'installers' => [
        'windows' => 'qz-tray-windows.exe',
        'linux' => 'qz-tray-linux.deb',
        'macos' => 'qz-tray-macos.pkg',
    ],
];
