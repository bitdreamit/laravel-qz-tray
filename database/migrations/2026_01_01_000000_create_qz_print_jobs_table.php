<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // v1.1.1: the primary key itself is now either a uuid or a bigint,
        // controlled by config('qz-tray.id_type') — no more separate `id`
        // (bigint, internal) + `uuid` (string, public-facing) pair. Reading
        // config() during a migration is safe; the app is fully booted by
        // the time migrations run.
        //
        //   'uuid'   (default) — $table->uuid('id')->primary(). The id is
        //             never a guessable sequential integer, so it's safe to
        //             hand straight back to the client and used as-is by
        //             GET /qz/jobs and DELETE /qz/jobs/{id}.
        //   'bigint' — $table->id() (auto-increment). Slightly smaller/
        //             faster index for installs that don't care about
        //             exposing sequential ids (e.g. fully internal/admin-
        //             only queue) or that want to stay consistent with an
        //             existing bigint-only schema convention.
        $usesUuid = config('qz-tray.id_type', 'uuid') === 'uuid';

        Schema::create('qz_print_jobs', function (Blueprint $table) use ($usesUuid) {
            if ($usesUuid) {
                $table->uuid('id')->primary();
            } else {
                $table->id();
            }
            // Project/tenant identifier. This package is reused across
            // multiple client projects (bitdreamit.com) whose "project" or
            // "tenant" table's primary key is bigint in some apps and uuid
            // in others. A plain unsignedBigInteger here would silently
            // truncate/reject a uuid value from any uuid-keyed project —
            // same class of bug user_id had before nullableMorphs. Stored
            // as a string so either "482" or
            // "b2b1f6c0-3b3d-4c9a-9e2e-1a2b3c4d5e6f" fits without a schema
            // change; validate the incoming value's *shape* at the
            // controller layer instead of constraining it at the DB layer.
            $table->string('tenant_id')->nullable();
            // Use a nullable string for user_id so the package works with both
            // integer-keyed (default) and UUID/ULID-keyed user models without
            // breaking the FK constraint. We index it for fast lookups.
            $table->nullableMorphs('user');
            // Identifies the physical workstation/browser that submitted the
            // job, independent of the logged-in user. Populated from the
            // `X-Device-Id` header sent by smart-print.js (a UUID persisted
            // in that browser's localStorage). This is what makes it possible
            // to tell apart two different PCs printing through the same
            // shared user session (e.g. a kiosk/lab-analyzer login), and is
            // required for correct multi-workstation printer-memory scoping.
            $table->uuid('device_id')->nullable();
            $table->string('printer_name');
            $table->string('document_url')->nullable();
            $table->string('document_type')->default('pdf');
            $table->integer('copies')->default(1);
            $table->string('status')->default('pending'); // pending, processing, completed, failed, cancelled
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'user_type', 'status']);
            $table->index(['device_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qz_print_jobs');
    }
};
