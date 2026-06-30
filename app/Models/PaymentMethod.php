<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentMethod extends Model
{
    use HasFactory;
    protected $table = 'payment_methods';
    public $timestamps = false;
    protected $fillable = [
        'method_name',
        'method_code',
        'requires_reference',
        'is_active',
        'organization_id',
    ];
    protected $casts = [
        'requires_reference' => 'boolean',
        'is_active' => 'boolean',
    ];
}
