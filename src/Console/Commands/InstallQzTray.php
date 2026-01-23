<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallQzTray extends Command
{
    protected $signature = 'qz:install
                            {--force : Force re-publish all assets}
                            {--no-cert : Skip certificate generation}';

    protected $description = 'Install QZ Tray package';

    public function handle(): int
    {
        $this->info('🚀 Installing Laravel QZ Tray Package...');
        $this->newLine();

        $this->publishStep('📁 Publishing configuration...', 'qz-config');
        $this->publishStep('🗃️ Publishing migrations...', 'qz-migrations');
        $this->publishStep('🗃️ Publishing blade views...', 'qz-blade');
        $this->publishStep('📦 Publishing JavaScript assets...', 'qz-assets');

        if (! $this->option('no-cert') && $this->getApplication()->has('qz:generate-certificate')) {
            $this->info('🔐 Generating certificate...');
            $this->call('qz:generate-certificate', [
                '--force' => $this->option('force'),
                '--show' => true,
            ]);
        }

        File::ensureDirectoryExists(storage_path('qz'), 0755);

        $this->newLine();
        $this->info('✅ QZ Tray installed successfully!');
        $this->newLine();

        $this->info('📋 Next Steps:');
        $this->line('1. Download QZ Tray: https://qz.io/download');
        $this->line('2. Run migrations: php artisan migrate');
        $this->line('3. Visit: /qz/status');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function publishStep(string $message, string $tag): void
    {
        $this->info($message);

        $this->callSilent('vendor:publish', [
            '--tag' => $tag,
            '--force' => $this->option('force'),
        ]);
    }
}
