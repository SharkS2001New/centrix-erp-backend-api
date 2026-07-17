<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoSupplierInvoice extends Model
{
    use HasFactory;

    protected $table = 'lpo_supplier_invoices';

    /** Table has created_at only (no updated_at). */
    public const UPDATED_AT = null;

    protected $fillable = [
        'lpo_no', 'supplier_id', 'supplier_invoice_number', 'invoice_date', 'invoice_amount',
        'file_path', 'file_name', 'mime_type', 'file_size', 'uploaded_by',
    ];

    public function lpo()
    {
        return $this->belongsTo(LpoMst::class, 'lpo_no', 'lpo_no');
    }
}
