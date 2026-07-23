<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable, correctly-scoped replacement for the Cache-only printer memory
 * used pre-1.1. The old implementation kept a single global Cache key per
 * `path` (`qz.printer.{path}`) as a fallback whenever a request had no
 * session value yet — meaning workstation/user A's printer choice could
 * silently become workstation/user B's default the next time B opened the
 * same page and B's own session/device had not yet set a preference.
 *
 * This table makes every stored preference explicitly scoped to exactly one
 * identity so two different identities can never read each other's printer:
 *
 *   identity_type = 'user'    -> identity_value = auth()->id() (string form)
 *   identity_type = 'device'  -> identity_value = client UUID (X-Device-Id)
 *   identity_type = 'session' -> identity_value = session()->getId()
 *
 * Both an authenticated user AND an anonymous device/session can be stored
 * side by side ("both support") — QzSecurityController prefers user, then
 * device, then session, and never mixes one identity's row into another's
 * response.
 *
 * v1.2.0: `id` and `tenant_id` now use config('qz-tray.id_type') — native
 * `uuid`/`unsignedBigInteger` columns, matching the same convention applied
 * to qz_print_jobs. This replaces the earlier plain-string tenant_id (with
 * an '' sentinel for "no tenant", chosen specifically because MySQL's
 * unique index treats NULL as distinct from every other NULL and would
 * silently stop enforcing uniqueness for the common single-tenant case).
 * A real `uuid` column can't hold '' at all — Postgres rejects it outright
 * — so id_type='uuid' installs go back to nullable with that known
 * limitation: two single-tenant rows for the same identity+path aren't
 * strictly DB-enforced-unique. This isn't a correctness bug in practice —
 * setPrinter()/getPrinter() always match on explicit WHERE conditions
 * (updateOrInsert), never on the DB constraint — it only means the unique
 * index is a weaker safety net against a genuine race condition than it
 * was under the string/'' approach for that one column type.
 */
return new class extends Migration
{
    public function up(): void
    {
        $usesUuid = config('qz-tray.id_type', 'uuid') === 'uuid';

        Schema::create('qz_printer_preferences', function (Blueprint $table) use ($usesUuid) {
            if ($usesUuid) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable();
            } else {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
            }
            $table->string('identity_type', 20); // user | device | session
            $table->string('identity_value');    // user id, device UUID, or session id
            $table->string('path', 500);
            $table->string('printer_name');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'identity_type', 'identity_value', 'path'],
                'qz_pref_tenant_identity_path_unique'
            );
            $table->index(['tenant_id', 'identity_type', 'identity_value'], 'qz_pref_tenant_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qz_printer_preferences');
    }
};
