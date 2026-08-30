<?php

namespace Bitdreamit\QzTray\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired every time a print job is logged or re-reported through
 * POST /qz/print (v1.2.1, additive). Host apps can listen to mirror print
 * activity into their own audit trail, notifications, or billing meters:
 *
 *   Event::listen(\Bitdreamit\QzTray\Events\PrintJobLogged::class, function (PrintJobLogged $e) {
 *       logger("Job {$e->jobId} ({$e->documentType}) for printer {$e->printer}");
 *   });
 */
class PrintJobLogged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $jobId,
        public string $printer,
        public string $documentType,
        public string $status,
        public bool $dbLogged,
    ) {}
}
