<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvestorContribution extends Model
{
    use SoftDeletes;

    public const TYPE_CASH = 'cash';

    public const TYPE_STOCK = 'stock';

    protected $fillable = [
        'organization_id',
        'investor_id',
        'branch_id',
        'contribution_type',
        'contribution_date',
        'amount',
        'payment_method_id',
        'payment_code',
        'reference_number',
        'supplier_id',
        'lpo_no',
        'supplier_payment_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'contribution_date' => 'date',
        'amount' => 'float',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InvestorProductBatch::class, 'contribution_id');
    }
}
