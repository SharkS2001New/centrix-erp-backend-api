<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HospitalityNightAudit extends Model
{
    protected $table = 'hospitality_night_audits';

    protected $fillable = [
        'organization_id',
        'business_date',
        'ran_by',
        'rooms_posted',
        'amount_posted',
        'details',
    ];

    protected $casts = [
        'business_date' => 'date',
        'amount_posted' => 'decimal:2',
        'details' => 'array',
    ];
}
