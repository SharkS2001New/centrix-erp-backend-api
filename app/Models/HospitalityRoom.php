<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalityRoom extends Model
{
    protected $table = 'hospitality_rooms';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'room_type_id',
        'room_number',
        'floor',
        'status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(HospitalityRoomType::class, 'room_type_id');
    }

    public function folios(): HasMany
    {
        return $this->hasMany(HospitalityFolio::class, 'room_id');
    }
}
