<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qz_print_jobs', function (Blueprint $table) {
            $table->id();
            // Public-facing job identifier. Never expose the auto-increment
            // `id` to the client — it leaks row counts and is guessable.
            // uniqid() (used pre-1.1) is time-based and not collision-safe
            // under concurrent requests from multiple workstations; uuid4
            // is generated server-side in the controller via Str::uuid().
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tenant_id')->nullable();
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
            $table->index(['status', 'created_at']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qz_print_jobs');
    }
};
