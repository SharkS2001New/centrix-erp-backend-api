<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WorkShift extends Model
{
    use HasFactory;

    protected $table = 'work_shifts';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'shift_code',
        'shift_name',
        'start_time',
        'end_time',
        'crosses_midnight',
        'works_saturday',
        'works_sunday',
        'works_public_holidays',
        'is_active',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'works_saturday' => 'boolean',
        'works_sunday' => 'boolean',
        'works_public_holidays' => 'boolean',
        'is_active' => 'boolean',
    ];
}
