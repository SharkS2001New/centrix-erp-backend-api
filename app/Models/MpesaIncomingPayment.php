<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaIncomingPayment extends Model
{
    protected $fillable = [
        'organization_id',
        'transaction_id',
        'phone_number',
        'bill_ref_number',
        'payer_name',
        'business_short_code',
        'parsed_order_num',
        'parsed_customer_num',
        'amount',
        'applied_amount',
        'source',
        'status',
        'applied_cart_id',
        'applied_sale_id',
        'applied_invoice_id',
        'match_method',
        'match_confidence',
        'reconciliation_status',
        'matched_by_user_id',
        'reconciliation_notes',
        'stk_request_id',
        'received_at',
        'applied_at',
        'matched_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
        'applied_at' => 'datetime',
        'matched_at' => 'datetime',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'applied_sale_id');
    }
}
