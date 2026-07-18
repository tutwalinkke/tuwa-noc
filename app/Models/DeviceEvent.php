<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'tenant_id',
        'severity',
        'type',
        'previous_status',
        'new_status',
        'message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
