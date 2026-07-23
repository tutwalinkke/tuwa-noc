<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceProvisioningCode extends Model
{
    protected $fillable = [
        'code',
        'tenant_id',
        'device_type',
        'created_by_user_id',
        'device_id',
        'wireguard_public_key',
        'assigned_wg_ip',
        'used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRedeemable(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }
}
