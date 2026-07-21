<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    protected $fillable = [
        'device_event_id',
        'device_id',
        'tenant_id',
        'status',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'resolved_at',
        'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function deviceEvent()
    {
        return $this->belongsTo(DeviceEvent::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
