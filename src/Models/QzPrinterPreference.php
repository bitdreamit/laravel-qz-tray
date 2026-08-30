<?php

namespace Bitdreamit\QzTray\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model over qz_printer_preferences (v1.2.1, additive).
 *
 * One row = one (tenant, identity, path) -> printer mapping. Identity types:
 * 'user' | 'device' | 'session' — see the migration docblock for the full
 * scoping story. Handy for admin screens that want to inspect or reset what
 * each workstation remembers:
 *
 *   QzPrinterPreference::forDevice($uuid)->where('path', '/invoices')->get();
 */
class QzPrinterPreference extends Model
{
    protected $table = 'qz_printer_preferences';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'identity_type',
        'identity_value',
        'path',
        'printer_name',
    ];

    public function getIncrementing(): bool
    {
        return config('qz-tray.id_type', 'uuid') !== 'uuid';
    }

    public function getKeyType(): string
    {
        return config('qz-tray.id_type', 'uuid') === 'uuid' ? 'string' : 'int';
    }

    public function scopeForIdentity($query, string $type, string $value)
    {
        return $query->where('identity_type', $type)->where('identity_value', $value);
    }

    public function scopeForDevice($query, string $deviceId)
    {
        return $query->forIdentity('device', $deviceId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->forIdentity('user', (string) $userId);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $tenantId === null ? $query : $query->where('tenant_id', $tenantId);
    }
}
