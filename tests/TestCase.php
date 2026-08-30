<?php

namespace Bitdreamit\QzTray\Tests;

use Bitdreamit\QzTray\QzTrayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [QzTrayServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Minimal sqlite schema mirroring the package migrations so
        // endpoint tests can run without a real database server.
        $app['db']->connection()->getSchemaBuilder()->create('qz_print_jobs', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('client_job_id')->nullable();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->string('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('printer_name');
            $table->string('document_url')->nullable();
            $table->string('document_type')->default('pdf');
            $table->integer('copies')->default(1);
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique('client_job_id');
        });

        $app['db']->connection()->getSchemaBuilder()->create('qz_printer_preferences', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('identity_type', 20);
            $table->string('identity_value');
            $table->string('path', 500);
            $table->string('printer_name');
            $table->timestamps();
            $table->unique(['tenant_id', 'identity_type', 'identity_value', 'path']);
        });
    }
}
