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
    ];
    protected $casts = [
        'is_active' => 'boolean',
        'receipt_payment_details' => 'array',
    ];
}
