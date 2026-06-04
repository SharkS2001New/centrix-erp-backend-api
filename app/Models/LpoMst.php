<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoMst extends Model
{
    use HasFactory;

    protected $table = 'lpo_mst';
    protected $primaryKey = 'lpo_no';
    public $timestamps = false;
    protected $fillable = [
        'supplier_id', 'reference_number', 'total_amount', 'vat_amount', 'net_amount',
        'created_by', 'due_date', 'lpo_status_code', 'delivery_address', 'cleared_flag',
        'cleared_by', 'cleared_at', 'email_sent_flag', 'sent_at', 'sent_by',
        'supplier_invoice_no', 'terms', 'instructions', 'deleted_by', 'deleted_at',
    ];
}
