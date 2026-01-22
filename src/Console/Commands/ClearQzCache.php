<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearQzCache extends Command
{
    protected $signature = 'qz:clear-cache
                            {--all : Clear all cache including session}
                            {--session : Clear session printer data}';

    protected $description = 'Clear QZ Tray cache';

    public function handle()
    {
        $this->info('🧹 Clearing QZ Tray cache...');

        $cleared = 0;

        // Clear cache entries
        $keys = Cache::get('qz.printer_keys', []);
        foreach ($keys as $key) {
            if (Cache::forget($key)) {
                $cleared++;
            }
        }

        Cache::forget('qz.printer_keys');

        $this->line("Cleared {$cleared} cache entries");

        if ($this->option('all') || $this->option('session')) {
            $this->info('Clearing session data...');
            // Note: Session clearing needs to be done in web context
            $this->line('Run: php artisan session:clear or visit /qz/clear-cache');
        }

        $this->info('✅ Cache cleared successfully!');

        return 0;
    }
}
