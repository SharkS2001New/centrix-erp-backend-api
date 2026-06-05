<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'suppliers';
    protected $fillable = [
        'supplier_code', 'supplier_name', 'contact_person', 'email', 'phone',
        'alternate_phone', 'address', 'town', 'tax_pin', 'additional_info',
        'contacts', 'organization_id', 'is_active', 'credit_limit', 'opening_balance',
        'current_balance', 'created_by', 'deleted_by', 'deleted_at',
    ];

    protected $casts = [
        'contacts' => 'array',
        'is_active' => 'boolean',
        'credit_limit' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
