<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalityOutlet extends Model
{
    protected $table = 'hospitality_outlets';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'code',
        'name',
        'outlet_type',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function floorTables(): HasMany
    {
        return $this->hasMany(HospitalityFloorTable::class, 'outlet_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(HospitalityCheck::class, 'outlet_id');
    }
}
