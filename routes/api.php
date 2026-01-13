<?php

use Illuminate\Support\Facades\Route;
use Bitdreamit\QzTray\Http\Controllers\QzSecurityController;

// API routes (sanctum protected)
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

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
