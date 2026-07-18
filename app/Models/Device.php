<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'ip_address',
        'type',
        'manufacturer',
        'model',
        'site',
        'customer_id',
        'snmp_community',
        'status',
        'last_checked_at',
        'last_seen_up_at',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'last_seen_up_at' => 'datetime',
        ];
    }

    public function interfaces()
    {
        return $this->hasMany(DeviceInterface::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
