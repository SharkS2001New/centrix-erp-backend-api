<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoSupplierInvoice extends Model
{
    use HasFactory;

    protected $table = 'lpo_supplier_invoices';

    public $timestamps = false;
    protected $fillable = [
        'lpo_no',
        'supplier_id',
        'supplier_invoice_number',
        'invoice_date',
        'invoice_amount',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
    ];
}
