<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'service_address',
        'status',
    ];

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
