<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PickingList extends Model
{
    protected $fillable = [
        'branch_id',
        'trip_id',
        'route_id',
        'list_date',
        'list_number',
        'status',
        'picker_name',
        'completed_at',
        'locked_at',
    ];

    protected $casts = [
        'list_date' => 'date',
        'completed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DispatchTrip::class, 'trip_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteModel::class, 'route_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PickingListLine::class, 'picking_list_id')->orderBy('line_no');
    }
}
