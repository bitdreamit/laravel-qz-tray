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
        'key_type'      => OPENSSL_KEYTYPE_RSA,
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
