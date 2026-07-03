<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Driver extends Model
{
    use HasFactory;

    protected $table = 'drivers';
    public $timestamps = false;

    protected $fillable = [
        'branch_id',
        'user_id',
        'default_vehicle_id',
        'default_route_id',
        'driver_code',
        'full_name',
        'phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function defaultVehicle()
    {
        return $this->belongsTo(Vehicle::class, 'default_vehicle_id');
    }

    public function defaultRoute()
    {
        return $this->belongsTo(RouteModel::class, 'default_route_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
