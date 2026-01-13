<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Certificate Settings
    |--------------------------------------------------------------------------
    */
    'cert_path' => storage_path('qz/certificate.pem'),
    'key_path' => storage_path('qz/private-key.pem'),
    'cert_ttl' => 3600, // 1 hour cache

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
        'host' => env('QZ_WEBSOCKET_HOST', '127.0.0.1'),
        'port' => env('QZ_WEBSOCKET_PORT', 8181),
        'retries' => 3,
        'timeout' => 30,
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
