<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallQzTray extends Command
{
    protected $signature = 'qz-tray:install {--force : Force publish all assets}';
    protected $description = 'Install Laravel QZ Tray package';

    public function handle()
    {
        $this->info('🚀 Installing Laravel QZ Tray...');

        // Publish config
        $this->call('vendor:publish', [
            '--tag' => 'qz-config',
            '--force' => $this->option('force'),
        ]);

        // Publish migrations
        $this->call('vendor:publish', [
            '--tag' => 'qz-migrations',
            '--force' => $this->option('force'),
        ]);

        // Publish JS assets
        $this->call('vendor:publish', [
            '--tag' => 'qz-assets',
            '--force' => $this->option('force'),
        ]);

        // Publish certificate
        $this->call('vendor:publish', [
            '--tag' => 'qz-certificate',
            '--force' => $this->option('force'),
        ]);

        // Publish installers
        $this->call('vendor:publish', [
            '--tag' => 'qz-installers',
            '--force' => $this->option('force'),
        ]);

        // Run migrations
        if ($this->confirm('Run migrations?', true)) {
            $this->call('migrate');
        }

        // Generate certificate if not exists
        $certPath = config('qz-tray.cert_path', storage_path('qz/certificate.pem'));
        if (!file_exists($certPath)) {
            $this->call('qz-tray:generate-certificate');
        }

        $this->info('✅ Laravel QZ Tray installed successfully!');
        $this->line('');
        $this->line('📦 Next steps:');
        $this->line('1. Add these scripts to your layout:');
        $this->line('   <script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.5/qz-tray.min.js"></script>');
        $this->line('   <script src="'.asset('vendor/qz-tray/smart-print.min.js').'"></script>');
        $this->line('');
        $this->line('2. Install QZ Tray on client machines:');
        $this->line('   Windows: '.route('qz.installer', 'windows'));
        $this->line('   Linux: '.route('qz.installer', 'linux'));
        $this->line('   macOS: '.route('qz.installer', 'macos'));
        $this->line('');
        $this->line('3. Start printing:');
        $this->line('   <button data-qz-print="/your/pdf/route">Print</button>');
    }
}
