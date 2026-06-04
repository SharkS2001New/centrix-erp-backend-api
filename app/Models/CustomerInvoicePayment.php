<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerInvoicePayment extends Model
{
    use HasFactory;

    protected $table = 'customer_invoice_payments';
    protected $fillable = [
        'customer_invoice_id', 'customer_num', 'payment_method_id', 'amount_paid',
        'amount_due_snapshot', 'cheque_number', 'reference_number', 'date_paid',
        'received_by', 'organization_id', 'notes',
    ];
}
