<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityFloorTable extends Model
{
    protected $table = 'hospitality_floor_tables';

    protected $fillable = [
        'organization_id',
        'outlet_id',
        'code',
        'label',
        'seats',
        'zone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'seats' => 'integer',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(HospitalityOutlet::class, 'outlet_id');
    }
}
