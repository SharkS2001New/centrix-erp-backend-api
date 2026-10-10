<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrivingVehicle extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'registration_number', 'make', 'model', 'year',
        'category', 'transmission', 'status', 'insurance_expires_on', 'inspection_expires_on',
        'odometer', 'maintenance_notes',
    ];

    protected $casts = [
        'year' => 'integer',
        'insurance_expires_on' => 'date',
        'inspection_expires_on' => 'date',
        'odometer' => 'integer',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(DrivingVehicleService::class, 'vehicle_id');
    }

    public function bookable(): bool
    {
        return $this->status === 'available';
    }
}
