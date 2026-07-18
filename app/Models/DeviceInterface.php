<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceInterface extends Model
{
    protected $fillable = [
        'device_id',
        'tenant_id',
        'if_index',
        'name',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function metrics()
    {
        return $this->hasMany(DeviceInterfaceMetric::class);
    }

    public function latestMetric()
    {
        return $this->hasOne(DeviceInterfaceMetric::class)->latestOfMany('polled_at');
    }
}
