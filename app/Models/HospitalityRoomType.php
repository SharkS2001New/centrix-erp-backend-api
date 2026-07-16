<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalityRoomType extends Model
{
    protected $table = 'hospitality_room_types';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'base_rate',
        'max_occupancy',
        'is_active',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'max_occupancy' => 'integer',
        'is_active' => 'boolean',
    ];

    public function rooms(): HasMany
    {
        return $this->hasMany(HospitalityRoom::class, 'room_type_id');
    }
}
