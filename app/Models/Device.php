<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'ip_address',
        'type',
        'manufacturer',
        'model',
        'site',
        'customer_id',
        'snmp_community',
        'wireguard_ip',
        'wireguard_public_key',
        'alert_threshold_in_bps',
        'alert_threshold_out_bps',
        'status',
        'last_checked_at',
        'last_seen_up_at',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'last_seen_up_at' => 'datetime',
        ];
    }

    public function interfaces()
    {
        return $this->hasMany(DeviceInterface::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function maintenanceWindows()
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    /**
     * Whether this device is currently under a scheduled maintenance
     * window — used to suppress alert emails (device-down and
     * bandwidth threshold) without suppressing polling or event
     * logging, so there's still a full record, just without the noise.
     */
    public function isInMaintenance(): bool
    {
        $now = now();

        return $this->maintenanceWindows()
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->exists();
    }
}
