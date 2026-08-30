<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT C2 (v1.2.1, additive): gives qz_print_jobs a stable handle on the
 * CLIENT-generated job id when the primary key itself is a bigint.
 *
 * In uuid mode the client job id IS the primary key, so POST /qz/print can
 * upsert on `id` directly. In bigint mode the PK is assigned by the database
 * and a client-supplied job_id had nowhere to live — the same job reported
 * twice (the SmartPrint client reports 'completed' after the initial log)
 * created a duplicate row per print. The nullable client_job_id column +
 * unique index restores idempotency: the second report for the same job
 * updates the original row instead of inserting a new one.
 *
 * Fully additive: safe to run on an existing install; existing rows get
 * NULL and keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('qz_print_jobs')) {
            return;
        }

        Schema::table('qz_print_jobs', function (Blueprint $table) {
            $table->uuid('client_job_id')->nullable()->after('id');
            $table->unique('client_job_id', 'qz_print_jobs_client_job_id_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('qz_print_jobs')) {
            return;
        }

        Schema::table('qz_print_jobs', function (Blueprint $table) {
            $table->dropUnique('qz_print_jobs_client_job_id_unique');
            $table->dropColumn('client_job_id');
        });
    }
};
