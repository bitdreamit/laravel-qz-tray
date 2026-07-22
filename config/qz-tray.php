<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    */
    'cert_path' => storage_path('qz/digital-certificate.txt'),
    'key_path'  => storage_path('qz/private-key.pem'),
    'cert_ttl'  => 3600,

    /*
    |--------------------------------------------------------------------------
    | Certificate Generation Settings
    |--------------------------------------------------------------------------
    */
    'certificate' => [
        'validity_days' => 7300,
        'algorithm'     => 'sha256',
        'key_bits'      => 2048,
        // Use integer 0 (= OPENSSL_KEYTYPE_RSA) so the config can be cached
        // via `php artisan config:cache` even when the openssl extension is
        // not loaded at cache time. OPENSSL_KEYTYPE_RSA === 0.
        'key_type'      => 0,
        'subject' => [
            'countryName'            => 'BD',
            'stateOrProvinceName'    => 'Rangpur',
            'localityName'           => 'Rangpur',
            'organizationName'       => 'Bit Dream IT',
            'organizationalUnitName' => 'Bit Dream IT',
            'commonName'             => 'Laravel QZ Tray',
            'emailAddress'           => 'info@bitdreamit.com',
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
    'default_printer'            => env('QZ_DEFAULT_PRINTER'),
    'allow_printer_switch'       => true,
    'remember_printer_per_page'  => true,
    'printer_cache_duration'     => 86400,

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
    | WebSocket Settings
    |--------------------------------------------------------------------------
    | Default port is 8181 (QZ Tray default).
    */
    'websocket' => [
        'host'    => env('QZ_WEBSOCKET_HOST', 'localhost'),
        'port'    => env('QZ_WEBSOCKET_PORT', 8181),
        'retries' => 1,
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser Fallback
    |--------------------------------------------------------------------------
    */
    'fallback' => [
        'enabled'         => true,
        'open_in_new_tab' => true,
        'show_warning'    => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hotkey Settings
    |--------------------------------------------------------------------------
    */
    'hotkey' => [
        'enabled'             => true,
        'combination'         => 'ctrl+shift+p',
        'require_confirmation' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Settings
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix'     => 'qz',
        'middleware' => ['web'],
        'throttle'   => '60,1',
        // API routes (routes/api.php) are disabled by default. Enable only
        // when you need a stateless, sanctum-protected surface.
        'api' => [
            'enabled'    => env('QZ_API_ENABLED', false),
            'prefix'     => 'api/qz',
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
        'level'   => env('QZ_LOGGING_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer Settings
    |--------------------------------------------------------------------------
    */
    'installers' => [
        'windows' => 'qz-tray-windows.exe',
        'linux'   => 'qz-tray-linux.deb',
        'macos'   => 'qz-tray-macos.pkg',
    ],
];
