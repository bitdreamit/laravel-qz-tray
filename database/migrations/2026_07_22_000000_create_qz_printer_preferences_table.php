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
 * v1.1.1: adds `tenant_id`, folding this table into the same bigint-or-uuid
 * tenant/project scoping already applied to qz_print_jobs (see BUG-24).
 * Stored as '' rather than null for "no tenant" — MySQL's unique index
 * treats NULL as distinct from every other NULL, which would silently stop
 * enforcing the uniqueness constraint below for the common single-tenant
 * case if left nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qz_printer_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->default(''); // '' = no tenant scoping (single-tenant apps)
            $table->string('identity_type', 20); // user | device | session
            $table->string('identity_value');    // user id, device UUID, or session id
            $table->string('path', 500);
            $table->string('printer_name');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'identity_type', 'identity_value', 'path'],
                'qz_pref_tenant_identity_path_unique'
            );
            $table->index(['tenant_id', 'identity_type', 'identity_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qz_printer_preferences');
    }
};
