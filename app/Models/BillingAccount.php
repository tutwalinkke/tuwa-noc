<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingAccount extends Model
{
    protected $fillable = [
        'tenant_id',
        'tenant_created_at',
        'trial_ends_at',
        'billing_anchor_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tenant_created_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'billing_anchor_date' => 'datetime',
        ];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'tenant_id', 'tenant_id');
    }
}
