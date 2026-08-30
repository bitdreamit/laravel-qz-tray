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
        $this->publishStep('🗃️  Publishing migrations...', 'qz-migrations');
        $this->publishStep('📄 Publishing blade views...', 'qz-blade');
        $this->publishStep('📦 Publishing JavaScript assets...', 'qz-assets');
        $this->publishStep('💿 Publishing QZ Tray installers...', 'qz-installers');

        // Ensure certificate storage directory exists
        $certDir = dirname(config('qz-tray.cert_path', storage_path('qz/digital-certificate.txt')));
        File::ensureDirectoryExists($certDir, 0755);

        if (! $this->option('no-cert')) {
            $this->info('🔐 Generating certificate...');
            $this->call('qz:generate-certificate', [
                '--force' => $this->option('force'),
                '--show'  => true,
            ]);
        }

        $this->newLine();
        $this->info('✅ QZ Tray installed successfully!');
        $this->newLine();

        $this->info('📋 Next Steps:');
        $this->line('  1. Download & install QZ Tray on client machines: https://qz.io/download');
        $this->line('  2. Run migrations: php artisan migrate');
        $this->line('  3. Add to your layout — fully SELF-HOSTED (no CDN; works offline & on LANs):');
        $this->line('       <script src="{{ asset(\'vendor/qz-tray/js/qz-tray.min.js\') }}"></script>');
        $this->line('       <script src="{{ asset(\'vendor/qz-tray/js/smart-print.js\') }}"></script>');
        $this->line('     (qz-tray.min.js ships with this package — published to public/vendor/qz-tray/js/)');
        $this->line('  4. Visit: /qz/status  to verify your setup');
        $this->line('  5. Using MULTIPLE SUBDOMAINS? The trust prompt is per-certificate — share ONE');
        $this->line('     certificate across all of them so clients only click "Always Allow" once:');
        $this->line('       here:  php artisan qz:certificate:export   (then import on each subdomain)');
        $this->line('       or:    php artisan qz:certificate:import --cert=fullchain.pem --key=privkey.pem');
        $this->line('              (a real CA cert — e.g. your Let\'s Encrypt fullchain — removes the prompt entirely)');
        $this->line('     Full guide: docs/multi-domain.md');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function publishStep(string $message, string $tag): void
    {
        $this->info($message);
        $this->callSilent('vendor:publish', [
            '--tag'   => $tag,
            '--force' => $this->option('force'),
        ]);
    }
}
