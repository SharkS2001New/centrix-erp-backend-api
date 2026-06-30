<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoSupplierInvoice extends Model
{
    use HasFactory;

    protected $table = 'lpo_supplier_invoices';
    protected $fillable = [
        'lpo_no', 'supplier_id', 'supplier_invoice_number', 'invoice_date', 'invoice_amount',
    ];

    public function lpo()
    {
        return $this->belongsTo(LpoMst::class, 'lpo_no', 'lpo_no');
    }
}
