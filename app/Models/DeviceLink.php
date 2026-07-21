<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceLink extends Model
{
    protected $fillable = [
        'device_a_id',
        'device_b_id',
        'tenant_id',
        'link_type',
        'description',
    ];

    public function deviceA()
    {
        return $this->belongsTo(Device::class, 'device_a_id');
    }

    public function deviceB()
    {
        return $this->belongsTo(Device::class, 'device_b_id');
    }
}
