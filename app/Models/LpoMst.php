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
        'organization_id', 'lpo_seq', 'supplier_id', 'reference_number', 'total_amount', 'vat_amount', 'net_amount',
        'created_by', 'created_at', 'due_date', 'lpo_status_code', 'delivery_address', 'cleared_flag',
        'cleared_by', 'cleared_at', 'email_sent_flag', 'sent_at', 'sent_by',
        'supplier_invoice_no', 'terms', 'instructions', 'deleted_by', 'deleted_at',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function lines()
    {
        return $this->hasMany(LpoTxn::class, 'lpo_no', 'lpo_no');
    }
}
