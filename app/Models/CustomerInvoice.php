<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInvoice extends Model
{
    use HasFactory;

    protected $table = 'customer_invoices';
    protected $fillable = [
        'invoice_number', 'sale_id', 'customer_num', 'branch_id', 'organization_id',
        'created_by', 'invoice_date', 'due_date', 'total_vat', 'invoice_total',
        'amount_paid', 'payment_status', 'notes', 'deleted_by', 'deleted_at',
    ];
    protected $casts = ['deleted_at' => 'datetime'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_num', 'customer_num');
    }
}
