<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteSchedule extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'route_id',
        'day_of_week',
        'default_driver_id',
        'default_vehicle_id',
        'departure_time',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteModel::class, 'route_id');
    }

    public function defaultDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'default_driver_id');
    }

    public function defaultVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'default_vehicle_id');
    }
}
