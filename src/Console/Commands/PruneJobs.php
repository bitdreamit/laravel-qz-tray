<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUDIT M9: qz_print_jobs was never pruned — every print attempt left a
 * permanent row, so a busy POS/lab deployment grew the table unbounded
 * while completed/failed/cancelled history lost value after days or weeks.
 *
 * Wire it into your scheduler (Laravel 11+: bootstrap/app.php; Laravel 10:
 * app/Console/Kernel.php):
 *
 *   $schedule->command('qz:prune-jobs --older-than=30')->daily();
 */
class PruneJobs extends Command
{
    protected $signature = 'qz:prune-jobs
                            {--older-than=30 : Delete jobs not updated in this many days}
                            {--status= : Only prune jobs with this status (pending, processing, completed, failed, cancelled)}
                            {--keep=0 : Keep the most recent N jobs regardless of age}
                            {--dry-run : Show what would be deleted without deleting it}';

    protected $description = 'Prune old rows from qz_print_jobs';

    public function handle(): int
    {
        if (! Schema::hasTable('qz_print_jobs')) {
            $this->error('qz_print_jobs table does not exist. Run `php artisan migrate` first.');

            return self::FAILURE;
        }

        $days = (int) $this->option('older-than');
        if ($days < 1) {
            $this->error('--older-than must be a positive number of days.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $query = DB::table('qz_print_jobs')->where('updated_at', '<', $cutoff);

        if ($status = $this->option('status')) {
            if (! in_array($status, ['pending', 'processing', 'completed', 'failed', 'cancelled'], true)) {
                $this->error('--status must be one of: pending, processing, completed, failed, cancelled');

                return self::FAILURE;
            }
            $query->where('status', $status);
        }

        $count = (clone $query)->count();

        // --keep=N preserves the newest N rows (by created_at) whatever their
        // age — useful when a report needs last month's history even though
        // the prune window is shorter.
        $keep = max(0, (int) $this->option('keep'));

        if ($keep > 0) {
            $keepCutoff = DB::table('qz_print_jobs')
                ->orderByDesc('created_at')
                ->skip($keep - 1)
                ->limit(1)
                ->value('created_at');

            if ($keepCutoff) {
                $query->where('created_at', '<', $keepCutoff);
            }
        }

        if ($count === 0) {
            $this->info("No print jobs older than {$days} day(s) found.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$count} print job(s) would be deleted (updated before {$cutoff->toDateTimeString()}).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Deleted {$deleted} print job(s) not updated since {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
