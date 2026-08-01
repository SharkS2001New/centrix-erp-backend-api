<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityRatePlan extends Model
{
    protected $table = 'hospitality_rate_plans';

    protected $fillable = [
        'organization_id',
        'room_type_id',
        'code',
        'name',
        'amount',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(HospitalityRoomType::class, 'room_type_id');
    }
}
