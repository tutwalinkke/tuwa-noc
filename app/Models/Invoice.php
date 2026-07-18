<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'tenant_id',
        'type',
        'device_count',
        'amount',
        'period_start',
        'period_end',
        'due_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'due_at' => 'datetime',
        ];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
