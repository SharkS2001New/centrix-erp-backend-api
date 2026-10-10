<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrivingPayment extends Model
{
    protected $fillable = [
        'organization_id', 'invoice_id', 'student_id', 'amount', 'method', 'reference',
        'received_on', 'received_by', 'provider_confirmed',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'received_on' => 'date',
        'provider_confirmed' => 'boolean',
    ];

    public function refunds(): HasMany
    {
        return $this->hasMany(DrivingRefund::class, 'payment_id');
    }
}
