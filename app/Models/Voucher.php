<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $table = 'vouchers';

    protected $fillable = [
        'organization_id',
        'voucher_code',
        'voucher_kind',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'initial_balance',
        'balance',
        'min_order_amount',
        'max_redemptions',
        'redemption_count',
        'valid_from',
        'valid_until',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'discount_value' => 'float',
        'initial_balance' => 'float',
        'balance' => 'float',
        'min_order_amount' => 'float',
        'max_redemptions' => 'integer',
        'redemption_count' => 'integer',
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];
}
