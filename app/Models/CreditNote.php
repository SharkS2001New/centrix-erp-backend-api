<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_note_no',
        'customer_return_id',
        'organization_id',
        'branch_id',
        'sale_id',
        'customer_num',
        'credit_date',
        'total_amount',
        'refund_method',
        'reason',
        'notes',
        'kra_status',
        'kra_relevant_invoice_number',
        'kra_refund_reason_code',
        'kra_invoice_number',
        'kra_cu_inv_no',
        'kra_receipt_signature',
        'kra_signature_link',
        'kra_serial_number',
        'kra_timestamp',
        'kra_request_payload',
        'kra_response_payload',
        'kra_error_message',
    ];

    protected $casts = [
        'credit_date' => 'date',
        'total_amount' => 'decimal:2',
        'kra_request_payload' => 'array',
        'kra_response_payload' => 'array',
    ];

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_num', 'customer_num');
    }
}
