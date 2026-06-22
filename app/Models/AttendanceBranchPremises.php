<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceBranchPremises extends Model
{
    use HasFactory;

    protected $table = 'attendance_branch_premises';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'latitude',
        'longitude',
        'radius_metres',
        'updated_by',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'radius_metres' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
