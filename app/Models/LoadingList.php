<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoadingList extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'trip_id',
        'route_id',
        'list_date',
        'status',
        'prepared_by_name',
        'checked_by_name',
        'locked_at',
        'total_amount',
    ];

    protected $casts = [
        'list_date' => 'date',
        'locked_at' => 'datetime',
        'total_amount' => 'float',
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
        return $this->hasMany(LoadingListLine::class, 'loading_list_id')->orderBy('line_no');
    }
}
