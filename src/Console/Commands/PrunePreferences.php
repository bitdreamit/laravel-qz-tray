<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * qz_printer_preferences rows are never TTL'd — the pre-1.1 Cache-backed
 * printer memory expired automatically via printer_cache_duration, but the
 * DB-backed replacement (BUG-19) doesn't age out on its own. Most
 * installs never need this (a stale row just means the picker defaults to
 * an out-of-date printer once, then gets overwritten on next selection),
 * but for large multi-device/multi-session deployments — every anonymous
 * `session` identity leaves a permanent row — this keeps the table from
 * growing unbounded. Not scheduled automatically; wire it into
 * app/Console/Kernel.php (or bootstrap/app.php on Laravel 11+) yourself if
 * you want it to run periodically:
 *
 *   $schedule->command('qz:prune-preferences --older-than=90')->weekly();
 */
class PrunePreferences extends Command
{
    protected $signature = 'qz:prune-preferences
                            {--older-than=90 : Delete preferences not updated in this many days}
                            {--type= : Only prune a specific identity_type (device, user, session)}
                            {--dry-run : Show what would be deleted without deleting it}';

    protected $description = 'Prune stale rows from qz_printer_preferences';

    public function handle(): int
    {
        if (! Schema::hasTable('qz_printer_preferences')) {
            $this->error('qz_printer_preferences table does not exist. Run `php artisan migrate` first.');
            return self::FAILURE;
        }

        $days = (int) $this->option('older-than');
        if ($days < 1) {
            $this->error('--older-than must be a positive number of days.');
            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = DB::table('qz_printer_preferences')->where('updated_at', '<', $cutoff);

        if ($type = $this->option('type')) {
            if (! in_array($type, ['device', 'user', 'session'], true)) {
                $this->error('--type must be one of: device, user, session');
                return self::FAILURE;
            }
            $query->where('identity_type', $type);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No preferences older than {$days} day(s) found.");
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$count} preference(s) would be deleted (updated before {$cutoff->toDateTimeString()}).");
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("✅ Deleted {$deleted} preference(s) not updated since {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
