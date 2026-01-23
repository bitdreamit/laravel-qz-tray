<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    */
    'cert_path' => storage_path('qz/digital-certificate.txt'),  // QZ Tray expects this exact name
    'key_path' => storage_path('qz/private-key.pem'),           // QZ Tray expects this exact name
    'cert_ttl' => 3600, // 1 hour cache

    /*
    |--------------------------------------------------------------------------
    | Certificate Generation Settings
    |--------------------------------------------------------------------------
    */
    'certificate' => [
        'generate_demo_style' => true,
        'validity_days' => 7300, // 20 years like demo
        'algorithm' => 'sha256',
        'key_bits' => 2048,
        'key_type' => OPENSSL_KEYTYPE_RSA,

        // QZ Tray Demo Certificate Details
        'subject' => [
            'countryName' => 'BD',
            'stateOrProvinceName' => 'Rangpur',
            'localityName' => 'Rangpur',
            'organizationName' => 'Bit Dream IT',
            'organizationalUnitName' => 'Bit Dream IT',
            'commonName' => 'Laravel QZ Tray',
            'emailAddress' => 'info@bitdreamit.com',
        ],

        // File names for QZ Tray compatibility (already set above)
        'files' => [
            'certificate' => 'digital-certificate.txt',  // QZ Tray expects this
            'private_key' => 'private-key.pem',          // QZ Tray expects this
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Printer Settings
    |--------------------------------------------------------------------------
    */
    'default_printer' => env('QZ_DEFAULT_PRINTER'),
    'allow_printer_switch' => true,
    'remember_printer_per_page' => true,
    'printer_cache_duration' => 86400, // 24 hours

    /*
    |--------------------------------------------------------------------------
    | WebSocket Settings
    |--------------------------------------------------------------------------
    */
    'websocket' => [
        'host' => env('QZ_WEBSOCKET_HOST', 'localhost'),
        'port' => env('QZ_WEBSOCKET_PORT', 8182),
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
