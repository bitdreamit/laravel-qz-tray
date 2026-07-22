<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearQzCache extends Command
{
    protected $signature = 'qz:clear-cache
                            {--all : Clear all cache including session}
                            {--session : Clear session printer data}';

    protected $description = 'Clear QZ Tray cache';

    public function handle(): int
    {
        $this->info('🧹 Clearing QZ Tray cache...');

        $cleared = 0;

        // v1.1.0 moved printer memory from Cache to the qz_printer_preferences
        // table (see BUG-19) — this command previously still cleared only the
        // old Cache-key mechanism, which stopped being written to, so it
        // silently did nothing useful on any post-1.1.0 install.
        if (Schema::hasTable('qz_printer_preferences')) {
            $cleared = DB::table('qz_printer_preferences')->count();
            DB::table('qz_printer_preferences')->truncate();
        }

        // Best-effort cleanup of any lingering pre-1.1.0 Cache keys.
        $legacyKeys = Cache::get('qz.printer_keys', []);
        foreach ($legacyKeys as $key) {
            Cache::forget($key);
        }
        Cache::forget('qz.printer_keys');

        $this->line("Cleared {$cleared} stored printer preference(s)");

        if ($this->option('all') || $this->option('session')) {
            $this->info('Clearing session data...');
            // Note: Session clearing needs to be done in web context
            $this->line('Run: php artisan session:clear or visit /qz/clear-cache');
        }

        $this->info('✅ Cache cleared successfully!');

        return self::SUCCESS;
    }
}
