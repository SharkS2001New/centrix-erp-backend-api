<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationCustomer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyCard extends Model
{
    use HasFactory;
    use BelongsToOrganizationCustomer;

    protected $table = 'loyalty_cards';

    protected $fillable = [
        'organization_id',
        'customer_num',
        'card_number',
        'phone_number',
        'points_balance',
        'is_active',
        'issued_at',
        'created_by',
    ];

    protected $casts = [
        'points_balance' => 'float',
        'is_active' => 'boolean',
        'issued_at' => 'date',
    ];

}
