<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LpoTxn extends Model
{
    use HasFactory;

    protected $table = 'lpo_txn';
    public $timestamps = false;
    protected $fillable = [
        'lpo_no', 'product_code', 'ordered_qty', 'uom', 'cost_price',
        'received_qty', 'offer_qty', 'markup_amount', 'markup_percent',
    ];

    public function lpo()
    {
        return $this->belongsTo(LpoMst::class, 'lpo_no', 'lpo_no');
    }
}
