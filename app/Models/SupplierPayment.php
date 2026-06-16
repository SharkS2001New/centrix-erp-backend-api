<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    protected $table = 'supplier_payments';

    protected $fillable = [
        'organization_id',
        'supplier_id',
        'lpo_no',
        'payment_method_id',
        'amount_paid',
        'manual_amount',
        'declared_payable',
        'amount_due_snapshot',
        'cheque_number',
        'reference_number',
        'date_paid',
        'paid_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'float',
            'manual_amount' => 'boolean',
            'declared_payable' => 'float',
            'amount_due_snapshot' => 'float',
            'date_paid' => 'date',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
