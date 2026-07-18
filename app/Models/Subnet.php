<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subnet extends Model
{
    protected $fillable = [
        'tenant_id',
        'cidr',
        'description',
        'site',
        'vlan_id',
    ];

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }
}
