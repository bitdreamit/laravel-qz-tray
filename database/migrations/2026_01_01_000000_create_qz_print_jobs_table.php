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
            $table->unsignedBigInteger('tenant_id')->nullable();
            // Use a nullable string for user_id so the package works with both
            // integer-keyed (default) and UUID/ULID-keyed user models without
            // breaking the FK constraint. We index it for fast lookups.
            $table->nullableMorphs('user');
            $table->string('printer_name');
            $table->string('document_url')->nullable();
            $table->string('document_type')->default('pdf');
            $table->integer('copies')->default(1);
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'user_type', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qz_print_jobs');
    }
};
