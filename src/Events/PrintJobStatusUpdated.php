<?php

namespace Bitdreamit\QzTray\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a print job's status changes (v1.2.1, additive): cancelled via
 * DELETE /qz/jobs/{id}, or processing/completed/failed via PATCH
 * /qz/jobs/{id}. Lets host apps react to the lifecycle — mark an invoice as
 * printed, alert on failed label prints, etc.
 */
class PrintJobStatusUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $jobId,
        public string $status,
    ) {}
}
