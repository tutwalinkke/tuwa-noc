<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceWindow extends Model
{
    protected $fillable = [
        'device_id',
        'tenant_id',
        'starts_at',
        'ends_at',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function isActive(): bool
    {
        $now = now();
        return $now->between($this->starts_at, $this->ends_at);
    }
}
