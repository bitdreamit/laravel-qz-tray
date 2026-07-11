<?php

use Illuminate\Support\Facades\Route;
use Bitdreamit\QzTray\Http\Controllers\QzSecurityController;

$apiConfig = config('qz-tray.routes.api', [
    'enabled'    => false,
    'prefix'     => 'api/qz',
    'middleware' => ['auth:sanctum', 'throttle:60,1'],
]);

// Guard: only register when explicitly enabled in config.
// The provider also checks this flag before loading the file, but we keep
// the guard here too so the file is safe to load on its own.
if (! ($apiConfig['enabled'] ?? false)) {
    return;
}

Route::group([
    'prefix'     => $apiConfig['prefix'] ?? 'api/qz',
    'middleware' => $apiConfig['middleware'] ?? ['auth:sanctum', 'throttle:60,1'],
], function () {

    // Printer management
    Route::get('/printers', [QzSecurityController::class, 'printers'])
        ->name('api.qz.printers');

    Route::post('/printer', [QzSecurityController::class, 'setPrinter'])
        ->name('api.qz.printer.set');

    // Print jobs
    Route::post('/print', [QzSecurityController::class, 'print'])
        ->name('api.qz.print');

    Route::get('/jobs', [QzSecurityController::class, 'jobs'])
        ->name('api.qz.jobs');

    Route::delete('/jobs/{id}', [QzSecurityController::class, 'cancelJob'])
        ->name('api.qz.jobs.cancel');

    // Status
    Route::get('/status', [QzSecurityController::class, 'status'])
        ->name('api.qz.status');
});
