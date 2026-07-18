<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceInterfaceMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'device_interface_id',
        'tenant_id',
        'oper_status',
        'in_octets',
        'out_octets',
        'in_bps',
        'out_bps',
        'polled_at',
    ];

    protected function casts(): array
    {
        return [
            'polled_at' => 'datetime',
        ];
    }

    public function interface()
    {
        return $this->belongsTo(DeviceInterface::class, 'device_interface_id');
    }
}
