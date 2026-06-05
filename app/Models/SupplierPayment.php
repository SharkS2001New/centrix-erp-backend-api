<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'supplier_payments';

    protected $fillable = [
        'supplier_id',
        'lpo_no',
        'lpo_supplier_invoice_id',
        'payment_method_id',
        'amount_paid',
        'amount_due_snapshot',
        'reference_number',
        'cheque_number',
        'date_paid',
        'paid_by',
        'organization_id',
        'notes',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'amount_due_snapshot' => 'decimal:2',
        'date_paid' => 'date',
        'created_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
