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
        //
        // v1.2.0: `tenant_id` and the `user` morph columns now use this
        // SAME id_type — native `uuid`/`unsignedBigInteger` columns instead
        // of the earlier plain-string compromise. This is a deliberate
        // project-wide-convention assumption: set QZ_JOB_ID_TYPE to match
        // whichever PK type your project's tenant/User tables actually
        // use (this package doesn't infer it). Native typed columns are
        // the right call when that holds — the query planner, index size,
        // and (if you add one yourself) a real FK constraint all benefit
        // versus an untyped string. If a single project genuinely mixes a
        // bigint tenant table with a uuid User model (or vice versa),
        // this config can only match one of them — that's a real
        // constraint of this approach, not an oversight.
        $usesUuid = config('qz-tray.id_type', 'uuid') === 'uuid';

        Schema::create('qz_print_jobs', function (Blueprint $table) use ($usesUuid) {
            if ($usesUuid) {
                $table->uuid('id')->primary();
                // Project/tenant identifier, uuid-typed to match id_type.
                // Nullable, no default — see the qz_printer_preferences
                // migration's docblock for why NULL (not '') is accepted
                // here: a native `uuid` column rejects '' outright on
                // Postgres, so there is no string-sentinel option once the
                // column is truly typed.
                $table->uuid('tenant_id')->nullable();
                $table->nullableUuidMorphs('user');
            } else {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->nullableMorphs('user');
            }
            // Identifies the physical workstation/browser that submitted the
            // job, independent of the logged-in user. Populated from the
            // `X-Device-Id` header sent by smart-print.js (a UUID persisted
            // in that browser's localStorage). This is what makes it possible
            // to tell apart two different PCs printing through the same
            // shared user session (e.g. a kiosk/lab-analyzer login), and is
            // required for correct multi-workstation printer-memory scoping.
            // Always uuid regardless of id_type — this is this package's
            // own device-identity convention, unrelated to any host-app PK.
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
