<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquityIncomingPayment extends Model
{
    protected $fillable = [
        'organization_id',
        'equity_bank_account_id',
        'matched_branch_id',
        'matched_route_id',
        'transaction_id',
        'phone_number',
        'bill_ref_number',
        'payer_name',
        'business_account_number',
        'parsed_order_num',
        'parsed_customer_num',
        'amount',
        'applied_amount',
        'source',
        'status',
        'applied_sale_id',
        'applied_invoice_id',
        'match_method',
        'match_confidence',
        'reconciliation_status',
        'matched_by_user_id',
        'reconciliation_notes',
        'received_at',
        'applied_at',
        'matched_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'applied_at' => 'datetime',
        'matched_at' => 'datetime',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'applied_sale_id');
    }

    public function equityBankAccount(): BelongsTo
    {
        return $this->belongsTo(EquityBankAccount::class, 'equity_bank_account_id');
    }
}
