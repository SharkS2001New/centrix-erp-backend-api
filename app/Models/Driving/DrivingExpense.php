<?php

namespace App\Models\Driving;

use Illuminate\Database\Eloquent\Model;

class DrivingExpense extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'category', 'amount', 'spent_on', 'supplier',
        'method', 'description', 'recorded_by', 'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_on' => 'date',
    ];
}
