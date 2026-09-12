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
        $this->line('  4. Visit: /qz/status  to verify your setup   |   /qz/setup = Client Setup Wizard');
        $this->newLine();
        $this->info('🎯 ZERO-PROMPT setup (no "Allow" dialogs, free — v1.4.0):');
        $this->line('  Path A (real CA):   php artisan qz:certificate:import --cert=fullchain.pem --key=privkey.pem');
        $this->line('                      then set QZ_WATCH_CERT/QZ_WATCH_KEY → renewals auto-reimport');
        $this->line('  Path B (own CA):    php artisan qz:generate-ca');
        $this->line('                      php artisan qz:generate-certificate --force --ca --domain=*.yourdomain.com');
        $this->line('                      php artisan qz:client-bundle --zip   → run setup.bat as admin on clients');
        $this->line('  Full guide: docs/zero-prompt.md   |   Multi-subdomain sharing: docs/multi-domain.md');
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
