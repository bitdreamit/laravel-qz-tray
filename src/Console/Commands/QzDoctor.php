<?php

namespace Bitdreamit\QzTray\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * qz:doctor — one-command health check for a QZ Tray install (v1.2.1,
 * additive). Checks every prerequisite the package depends on and prints a
 * pass/fail table, so support conversations start from a shared report
 * instead of guesswork:
 *
 *   php artisan qz:doctor
 */
class QzDoctor extends Command
{
    protected $signature = 'qz:doctor';

    protected $description = 'Check the QZ Tray package installation health';

    public function handle(): int
    {
        $this->info('QZ Tray Doctor — checking installation health...');
        $this->newLine();

        $issues = 0;
        $checks = [];

        // 1. PHP version
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $checks[] = ['PHP >= 8.1', PHP_VERSION, $phpOk];
        if (! $phpOk) {
            $issues++;
        }

        // 2. OpenSSL extension
        $opensslOk = extension_loaded('openssl');
        $checks[] = ['OpenSSL extension', $opensslOk ? 'loaded' : 'MISSING', $opensslOk];
        if (! $opensslOk) {
            $issues++;
        }

        // 3. Certificate files exist
        $certPath = config('qz-tray.cert_path');
        $keyPath  = config('qz-tray.key_path');
        $certOk   = $certPath && file_exists($certPath);
        $keyOk    = $keyPath && file_exists($keyPath);
        $checks[] = ['Digital certificate', $certOk ? $certPath : 'MISSING — run qz:generate-certificate', $certOk];
        $checks[] = ['Private key', $keyOk ? $keyPath : 'MISSING — run qz:generate-certificate', $keyOk];
        if (! $certOk || ! $keyOk) {
            $issues++;
        }

        // 4. Certificate expiry (only when the cert exists and parses)
        if ($certOk) {
            $parsed = openssl_x509_parse((string) file_get_contents($certPath));
            if ($parsed && isset($parsed['validTo_time_t'])) {
                $expiresAt = \Carbon\Carbon::createFromTimestamp($parsed['validTo_time_t']);
                $daysLeft  = (int) now()->diffInDays($expiresAt, false);
                $expOk     = $daysLeft > 30;
                $checks[]  = ['Certificate expiry', $expiresAt->toDateString()." ({$daysLeft} days left)", $expOk];
                if (! $expOk) {
                    $issues++;
                }
            } else {
                $checks[]  = ['Certificate expiry', 'certificate unreadable', false];
                $issues++;
            }
        }

        // 5. Private key permissions (best-effort; meaningless on Windows)
        if ($keyOk && DIRECTORY_SEPARATOR !== '\\') {
            $perms    = fileperms($keyPath) & 0777;
            $permOk   = ($perms & 0x006) === 0; // no group/other RW
            $checks[] = ['Private key permissions', sprintf('%o (should not be group/other writable)', $perms), $permOk];
            if (! $permOk) {
                $issues++;
            }
        }

        // 6. Database tables
        $jobsTable = Schema::hasTable('qz_print_jobs');
        $prefsTable = Schema::hasTable('qz_printer_preferences');
        $checks[] = ['qz_print_jobs table', $jobsTable ? 'migrated' : 'NOT MIGRATED — job logging disabled', $jobsTable];
        $checks[] = ['qz_printer_preferences table', $prefsTable ? 'migrated' : 'NOT MIGRATED — printer memory disabled', $prefsTable];
        if (! $jobsTable || ! $prefsTable) {
            $issues++;
        }

        // 7. client_job_id column (v1.2.1 additive migration)
        if ($jobsTable) {
            $hasClientJobId = Schema::hasColumn('qz_print_jobs', 'client_job_id');
            $checks[] = ['client_job_id column', $hasClientJobId ? 'present' : 'MISSING — run `php artisan migrate` (v1.2.1)', $hasClientJobId];
            if (! $hasClientJobId) {
                $issues++;
            }
        }

        // 8. Config sanity
        $idType = config('qz-tray.id_type', 'uuid');
        $idOk   = in_array($idType, ['uuid', 'bigint'], true);
        $checks[] = ['id_type', $idType, $idOk];
        if (! $idOk) {
            $issues++;
        }

        $prefix = config('qz-tray.routes.prefix', 'qz');
        $checks[] = ['Route prefix', "/{$prefix}", true];
        $checks[] = ['API routes', config('qz-tray.routes.api.enabled', false) ? 'enabled' : 'disabled (default)', true];

        $this->table(['Check', 'Value', 'Result'], collect($checks)
            ->map(fn ($c) => [$c[0], (string) $c[1], $c[2] ? 'PASS' : 'FAIL']));

        if ($issues === 0) {
            $this->info('All checks passed. Your QZ Tray installation looks healthy.');

            return self::SUCCESS;
        }

        $this->warn("{$issues} check(s) failed. Fix the FAIL rows above, then re-run qz:doctor.");

        return self::FAILURE;
    }
}
