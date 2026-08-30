<?php

namespace Bitdreamit\QzTray\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model over qz_print_jobs (v1.2.1, additive).
 *
 * The package previously exposed only raw DB::table() queries, forcing host
 * apps to re-implement status filtering and tenant scoping themselves. This
 * model gives them a first-class integration point:
 *
 *   QzPrintJob::query()
 *       ->where('tenant_id', $tenantId)
 *       ->where('status', 'completed')
 *       ->latest('created_at')
 *       ->limit(10)
 *       ->get();
 *
 * The primary key type (uuid vs auto-increment bigint) was fixed at
 * migration time by config('qz-tray.id_type'), so $incrementing and the
 * keyType are derived from the same config. Note this runs at model
 * construction; if you cache config this always matches the migrated schema.
 */
class QzPrintJob extends Model
{
    protected $table = 'qz_print_jobs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'client_job_id',
        'tenant_id',
        'user_id',
        'user_type',
        'device_id',
        'printer_name',
        'document_url',
        'document_type',
        'copies',
        'status',
        'error_message',
        'metadata',
        'processed_at',
    ];

    protected $casts = [
        'copies'       => 'integer',
        'metadata'     => 'array',
        'processed_at' => 'datetime',
    ];

    public function getIncrementing(): bool
    {
        return config('qz-tray.id_type', 'uuid') !== 'uuid';
    }

    public function getKeyType(): string
    {
        return config('qz-tray.id_type', 'uuid') === 'uuid' ? 'string' : 'int';
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $tenantId === null ? $query : $query->where('tenant_id', $tenantId);
    }

    public function scopeForDevice($query, string $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    public function scopeStale($query, int $days = 30)
    {
        return $query->where('updated_at', '<', now()->subDays($days));
    }
}
