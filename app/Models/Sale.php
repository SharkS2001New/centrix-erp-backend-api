<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Sale extends Model
{
    use HasFactory;

    protected $table = 'sales';
    const UPDATED_AT = null;

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    protected $fillable = [
        'order_num', 'branch_id', 'organization_id', 'channel', 'till_id',
        'float_session_id', 'cashier_id', 'customer_num', 'customer_name_override',
        'route_id', 'required_date', 'delivery_date', 'status', 'total_vat', 'order_total',
        'cash', 'mpesa_amount', 'equity_amount', 'kcb_amount', 'order_change',
        'payment_status', 'amount_paid', 'fulfillment_meta',
        'payment_method_code', 'is_credit_sale', 'stock_balanced', 'receipt_printed',
        'comments', 'archived', 'deleted_by', 'deleted_at', 'completed_at',
        'cancelled_at', 'cancelled_by',
    ];
    protected $casts = [
        'fulfillment_meta' => 'array',
        'amount_paid' => 'decimal:2',
        'required_date' => 'date',
        'delivery_date' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
