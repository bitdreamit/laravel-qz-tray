<?php

use Illuminate\Support\Facades\Route;
use Bitdreamit\QzTray\Http\Controllers\QzSecurityController;

$config = config('qz-tray.routes');

// routes/web.php
//Route::prefix('qz')->group(function () {
//    // Security endpoints (required by QZ Tray)
//    Route::get('/certificate', [QzController::class, 'certificate']);
//    Route::post('/sign', [QzController::class, 'sign']);
//
//    // Printer management
//    Route::get('/printers', [QzController::class, 'printers']);
//    Route::get('/printer/{path}', [QzController::class, 'getPrinter'])->where('path', '.*');
//    Route::post('/printer', [QzController::class, 'setPrinter']);
//
//    // Test routes
//    Route::get('/test/pdf', [QzController::class, 'testPdf']);
//});

Route::group([
    'prefix' => $config['prefix'] ?? 'qz',
    'middleware' => $config['middleware'] ?? ['web'],
], function () {

    // Security endpoints
    Route::get('/certificate', [QzSecurityController::class, 'certificate'])
        ->name('qz.certificate');

    Route::post('/sign', [QzSecurityController::class, 'sign'])
        ->name('qz.sign');

    // Printer management
    Route::get('/printers', [QzSecurityController::class, 'printers'])
        ->name('qz.printers');

    Route::post('/printer', [QzSecurityController::class, 'setPrinter'])
        ->name('qz.printer.set');

    Route::get('/printer/{path}', [QzSecurityController::class, 'getPrinter'])
        ->where('path', '.*')
        ->name('qz.printer.get');

    // Print jobs
    Route::post('/print', [QzSecurityController::class, 'print'])
        ->name('qz.print');

    Route::get('/jobs', [QzSecurityController::class, 'jobs'])
        ->name('qz.jobs');

    Route::delete('/jobs/{id}', [QzSecurityController::class, 'cancelJob'])
        ->name('qz.jobs.cancel');

    // Status & health
    Route::get('/status', [QzSecurityController::class, 'status'])
        ->name('qz.status');

    Route::get('/health', [QzSecurityController::class, 'health'])
        ->name('qz.health');

    // Installer downloads
    Route::get('/installer/{os}', [QzSecurityController::class, 'installer'])
        ->where('os', 'windows|linux|macos')
        ->name('qz.installer');
});
