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

        // 4. Certificate details: expiry, issuer, fingerprint, key match
        //    (v1.3.0 — the multi-subdomain trust diagnostics)
        if ($certOk) {
            $certPem = (string) file_get_contents($certPath);
            $parsed  = openssl_x509_parse($certPem);
            if ($parsed && isset($parsed['validTo_time_t'])) {
                $expiresAt = \Carbon\Carbon::createFromTimestamp($parsed['validTo_time_t']);
                $daysLeft  = (int) now()->diffInDays($expiresAt, false);
                $expOk     = $daysLeft > 30;
                $checks[]  = ['Certificate expiry', $expiresAt->toDateString()." ({$daysLeft} days left)", $expOk];
                if (! $expOk) {
                    $issues++;
                }

                // Fingerprint — compare across subdomains: QZ Tray's trust
                // prompt is keyed to this value, not to the domain.
                $sha1 = openssl_x509_fingerprint($certPem, 'sha1');
                $checks[] = ['Certificate fingerprint (SHA-1)', $sha1 ? implode(':', str_split($sha1, 2)) : 'unreadable', (bool) $sha1];

                // Issuer / self-signed detection with actionable guidance.
                $selfSigned = isset($parsed['subject'], $parsed['issuer']) && $parsed['subject'] === $parsed['issuer'];
                $issuerCn   = $parsed['issuer']['CN'] ?? '?';
                if ($selfSigned) {
                    $checks[] = ['Certificate issuer', "{$issuerCn} (self-signed — trust prompt on first connect; share this cert across subdomains, or import a CA cert via qz:certificate:import)", true];
                } else {
                    $checks[] = ['Certificate issuer', "{$issuerCn} (CA-signed — QZ Tray connects silently)", true];
                }

                // Cert/key pair match.
                if ($keyOk) {
                    $certRes = openssl_x509_read($certPem);
                    $keyRes  = openssl_pkey_get_private((string) file_get_contents($keyPath));
                    $pairOk  = $certRes && $keyRes && openssl_x509_check_private_key($certRes, $keyRes);
                    $checks[] = ['Certificate ↔ key match', $pairOk ? 'match' : 'MISMATCH — signing will fail; re-run qz:generate-certificate --force or qz:certificate:import', $pairOk];
                    if (! $pairOk) {
                        $issues++;
                    }
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

        // 9. (v1.4.0) Zero-prompt trust posture
        if ($certOk) {
            $caCertPath  = (string) (config('qz-tray.certificate.ca.cert_path') ?: storage_path('qz/ca/qz-root-ca.crt'));
            $hasCa       = is_file($caCertPath);
            $certPem     = (string) file_get_contents((string) $certPath);
            $chains      = $hasCa ? \Bitdreamit\QzTray\Support\CertKit::leafChainsTo($certPem, (string) file_get_contents($caCertPath)) : false;
            $selfSigned  = \Bitdreamit\QzTray\Support\CertKit::isSelfSigned($certPem);

            $mode = $selfSigned ? 'self-signed' : 'public-ca';
            if ($hasCa && $chains) {
                $mode = 'own-ca (zero-prompt once override.crt deployed)';
            }

            $checks[] = ['Trust mode (v1.4.0)', $mode, true];

            // Informational: a shared self-signed pair + "Always Allow" is a
            // valid posture too, so readiness never hard-fails the doctor.
            if ($mode === 'self-signed') {
                $checks[] = ['Zero-prompt readiness', 'INFORMATIONAL — for zero prompts run qz:generate-ca + qz:generate-certificate --ca, or import a real CA cert (docs/zero-prompt.md)', true];
            } else {
                $checks[] = ['Zero-prompt readiness', $mode === 'public-ca' ? 'READY — public chain, QZ Tray trusts silently' : 'READY — deploy override.crt bundle to clients (qz:client-bundle)', true];
            }

            $watch = (string) config('qz-tray.certificate.watch.source_cert', '');
            $checks[] = ['Renewal watcher', $watch !== '' ? "enabled ({$watch})" : 'not configured — expiry reported by qz:watch-certificate only', true];
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
