<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpAddress extends Model
{
    protected $fillable = [
        'subnet_id',
        'tenant_id',
        'address',
        'status',
        'device_id',
        'label',
    ];

    public function subnet()
    {
        return $this->belongsTo(Subnet::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
