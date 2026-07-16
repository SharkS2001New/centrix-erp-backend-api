<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityCheckPayment extends Model
{
    protected $table = 'hospitality_check_payments';

    protected $fillable = [
        'organization_id',
        'check_id',
        'payment_method_id',
        'method_code',
        'amount',
        'reference',
        'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(HospitalityCheck::class, 'check_id');
    }
}
