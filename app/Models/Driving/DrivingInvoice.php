<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DrivingInvoice extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'student_id', 'enrolment_id', 'invoice_number',
        'kind', 'amount', 'discount', 'status', 'issued_on', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'issued_on' => 'date',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(DrivingStudent::class, 'student_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DrivingPayment::class, 'invoice_id');
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(DrivingPayment::class, 'invoice_id')->latestOfMany();
    }

    public function netAmount(): float
    {
        return round((float) $this->amount - (float) $this->discount, 2);
    }
}
