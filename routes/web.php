<?php

use Bitdreamit\QzTray\Http\Controllers\QzSecurityController;
use Illuminate\Support\Facades\Route;

$config = config('qz-tray.routes', ['prefix' => 'qz', 'middleware' => ['web']]);

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

    // Certificate management
    Route::post('/generate', [QzSecurityController::class, 'generateCertificatePublic'])
        ->name('qz.generate');

    Route::post('/setup', [QzSecurityController::class, 'setup'])
        ->name('qz.setup');

    Route::post('/test-sign', [QzSecurityController::class, 'testSign'])
        ->name('qz.test-sign');

    // Cache management
    Route::post('/clear-cache', [QzSecurityController::class, 'clearCache'])
        ->name('qz.clear-cache');

    // Installer downloads
    Route::get('/installer/{os}', [QzSecurityController::class, 'installer'])
        ->where('os', 'windows|linux|macos')
        ->name('qz.installer');

    // Test endpoints
    Route::get('/test/pdf', [QzSecurityController::class, 'testPdf'])
        ->name('qz.test.pdf');

    Route::get('/test/connection', [QzSecurityController::class, 'testConnection'])
        ->name('qz.test.connection');

    Route::get('/test', [QzSecurityController::class, 'index'])->name('qz.test');

});
