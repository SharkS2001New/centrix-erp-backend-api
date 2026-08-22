<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class RouteModel extends Model
{
    use HasFactory;
    protected $table = 'routes';
    public $timestamps = false;
    protected $fillable = [
        'organization_id',
        'branch_id',
        'route_name',
        'route_markup_price',
        'direction',
        'is_active',
        'receipt_payment_details',
        'mpesa_paybill_account_id',
        'equity_bank_account_id',
    ];
    protected $casts = [
        'is_active' => 'boolean',
        'receipt_payment_details' => 'array',
    ];

    public function mpesaPaybillAccount()
    {
        return $this->belongsTo(MpesaPaybillAccount::class, 'mpesa_paybill_account_id');
    }

    public function equityBankAccount()
    {
        return $this->belongsTo(EquityBankAccount::class, 'equity_bank_account_id');
    }
}
