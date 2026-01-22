<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;

class InstallQzTray extends Command
{
    protected $signature = 'qz:install
                            {--force : Force re-publish all assets}
                            {--no-cert : Skip certificate generation}';

    protected $description = 'Install QZ Tray package';

    public function handle()
    {
        $this->info('🚀 Installing Laravel QZ Tray Package...');
        $this->newLine();

        // Publish config
        $this->info('📁 Publishing configuration...');
        $this->call('vendor:publish', [
            '--tag' => 'qz-config',
            '--force' => $this->option('force'),
        ]);

        // Publish migrations
        $this->info('🗃️ Publishing migrations...');
        $this->call('vendor:publish', [
            '--tag' => 'qz-migrations',
            '--force' => $this->option('force'),
        ]);

        // Publish assets
        $this->info('📦 Publishing JavaScript assets...');
        $this->call('vendor:publish', [
            '--tag' => 'qz-assets',
            '--force' => $this->option('force'),
        ]);

        // Generate certificate
        if (!$this->option('no-cert')) {
            $this->info('🔐 Generating certificate...');
            $this->call('qz:generate-certificate', [
                '--force' => $this->option('force'),
                '--show' => true,
            ]);
        }

        // Create necessary directories
        $directories = [
            storage_path('qz'),
            public_path('vendor/qz-tray'),
            public_path('vendor/qz-tray/installers'),
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
                $this->line('Created directory: ' . $directory);
            }
        }

        $this->newLine();
        $this->info('✅ QZ Tray installed successfully!');
        $this->newLine();

        $this->info('📋 Next Steps:');
        $this->line('1. Download and install QZ Tray from https://qz.io/download');
        $this->line('2. Run migrations: php artisan migrate');
        $this->line('3. Visit your application at /qz/status to verify setup');
        $this->line('4. Configure printers in QZ Tray desktop application');
        $this->newLine();

        $this->info('🔗 Available Endpoints:');
        $this->line('• /qz/certificate - Get certificate');
        $this->line('• /qz/sign - Sign data');
        $this->line('• /qz/status - Check status');
        $this->line('• /qz/health - Health check');
        $this->line('• /qz/printers - List printers');
        $this->newLine();

        return 0;
    }
}
