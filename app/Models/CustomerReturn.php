<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_no',
        'return_seq',
        'organization_id',
        'branch_id',
        'sale_id',
        'customer_num',
        'return_date',
        'refund_method',
        'reason',
        'proof_file_path',
        'proof_file_name',
        'proof_file_mime_type',
        'proof_file_size',
        'notes',
        'status',
        'total_amount',
        'stock_location',
        'returned_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'return_kind',
        'kra_original_invoice_number',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerReturnLine::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_num', 'customer_num');
    }

    public function returnedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }
}
