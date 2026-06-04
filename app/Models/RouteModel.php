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
        'route_name',
        'route_markup_price',
        'direction',
        'is_active',
    ];
    protected $casts = [
        'is_active' => 'boolean',
    ];
}
