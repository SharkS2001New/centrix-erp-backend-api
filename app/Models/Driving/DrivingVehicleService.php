<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;

class DrivingVehicleService extends Model
{
    protected $fillable = [
        'organization_id', 'vehicle_id', 'serviced_on', 'odometer', 'cost', 'notes',
    ];

    protected $casts = [
        'serviced_on' => 'date',
        'odometer' => 'integer',
        'cost' => 'decimal:2',
    ];
}
