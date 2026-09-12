<?php

use Bitdreamit\QzTray\Http\Controllers\QzSecurityController;
use Illuminate\Support\Facades\Route;

$config = config('qz-tray.routes', ['prefix' => 'qz', 'middleware' => ['web']]);

// Build the middleware stack, merging in the optional throttle middleware.
$middleware = $config['middleware'] ?? ['web'];
if (! empty($config['throttle'])) {
    $middleware[] = 'throttle:' . $config['throttle'];
}

Route::group([
    'prefix'     => $config['prefix'] ?? 'qz',
    'middleware' => $middleware,
], function () {

    // Security endpoints
    Route::get('/certificate', [QzSecurityController::class, 'certificate'])
        ->name('qz.certificate');

    // v1.4.0: download the trust root clients deploy as QZ Tray override.crt
    Route::get('/ca-certificate', [QzSecurityController::class, 'caCertificate'])
        ->name('qz.ca-certificate');

    // v1.4.0: download the ready-made Windows client trust bundle (.zip)
    Route::get('/client-bundle', [QzSecurityController::class, 'clientBundle'])
        ->name('qz.client-bundle');

    Route::post('/sign', [QzSecurityController::class, 'sign'])
        ->name('qz.sign');

    // Printer management
    Route::get('/printers', [QzSecurityController::class, 'printers'])
        ->name('qz.printers');

    Route::post('/printer', [QzSecurityController::class, 'setPrinter'])
        ->name('qz.printer.set');

    // AUDIT H3: query-param variant of /printer/{path}. The segment version
    // breaks on Apache when the path contains encoded slashes (%2F) — Apache
    // rejects them by default with a 404 — so the client now sends the page
    // path as ?path=. The original segment route is kept for compatibility.
    Route::get('/printer', [QzSecurityController::class, 'getPrinterByQuery'])
        ->name('qz.printer.get.query');

    Route::get('/printer/{path}', [QzSecurityController::class, 'getPrinter'])
        ->where('path', '.*')
        ->name('qz.printer.get');

    // Print jobs
    Route::post('/print', [QzSecurityController::class, 'print'])
        ->name('qz.print');

    Route::get('/jobs', [QzSecurityController::class, 'jobs'])
        ->name('qz.jobs');

    // AUDIT C2: dedicated job-status updates (processing/completed/failed)
    // so the client no longer has to replay POST /qz/print to change state.
    Route::patch('/jobs/{id}', [QzSecurityController::class, 'updateJobStatus'])
        ->name('qz.jobs.status');

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

    // v1.4.0: browser-facing Client Setup Wizard (same URI, GET verb)
    Route::get('/setup', [QzSecurityController::class, 'wizard'])
        ->name('qz.setup.wizard');

    Route::post('/test-sign', [QzSecurityController::class, 'testSign'])->name('qz.test-sign');

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

    Route::get('/smart', [QzSecurityController::class, 'smart'])->name('qz.smart');

});
