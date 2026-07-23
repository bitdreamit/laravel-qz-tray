<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $usesUuid = config('qz-tray.id_type', 'uuid') === 'uuid';

        Schema::create('qz_print_jobs', function (Blueprint $table) use ($usesUuid) {
            // 1. Primary Key
            if ($usesUuid) {
                $table->uuid('id')->primary();
            } else {
                $table->id();
            }

            // 2. Tenant ID matching the app's fixed key type
            if ($usesUuid) {
                $table->uuid('tenant_id')->nullable();
            } else {
                $table->unsignedBigInteger('tenant_id')->nullable();
            }

            // 3. User Polymorphic Relation
            if ($usesUuid) {
                $table->nullableUuidMorphs('user');
            } else {
                $table->nullableMorphs('user');
            }

            $table->uuid('device_id')->nullable();
            $table->string('printer_name');
            $table->string('document_url')->nullable();
            $table->string('document_type')->default('pdf');
            $table->integer('copies')->default(1);
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['user_type', 'user_id', 'status']);
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
