<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrganizationHoliday extends Model
{
    use HasFactory;

    protected $table = 'organization_holidays';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'holiday_date',
        'name',
        'is_active',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'is_active' => 'boolean',
    ];
}
