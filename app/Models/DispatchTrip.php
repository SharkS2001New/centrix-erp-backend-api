<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DispatchTrip extends Model
{
    protected $fillable = [
        'branch_id',
        'trip_code',
        'route_id',
        'driver_id',
        'vehicle_id',
        'scheduled_date',
        'status',
        'notes',
        'prepared_by_name',
        'prepared_at',
        'checked_by_name',
        'checked_at',
        'departed_at',
        'completed_at',
        'expected_cash',
        'collected_cash',
        'cash_variance',
        'settled_at',
        'settled_by',
        'stock_deducted_at',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'prepared_at' => 'datetime',
        'checked_at' => 'datetime',
        'departed_at' => 'datetime',
        'completed_at' => 'datetime',
        'settled_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
        'expected_cash' => 'float',
        'collected_cash' => 'float',
        'cash_variance' => 'float',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteModel::class, 'route_id');
    }

    public function routes(): BelongsToMany
    {
        return $this->belongsToMany(RouteModel::class, 'dispatch_trip_routes', 'trip_id', 'route_id')
            ->withTimestamps()
            ->orderBy('routes.route_name');
    }

    /** @return list<int> */
    public function routeIdList(): array
    {
        if ($this->relationLoaded('routes') && $this->routes->isNotEmpty()) {
            return $this->routes->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        return $this->route_id ? [(int) $this->route_id] : [];
    }

    public function isMultiRoute(): bool
    {
        return count($this->routeIdList()) > 1;
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sales(): BelongsToMany
    {
        return $this->belongsToMany(Sale::class, 'dispatch_trip_sales', 'trip_id', 'sale_id')
            ->withPivot('stop_seq')
            ->orderBy('dispatch_trip_sales.stop_seq');
    }

    public function loadingList(): HasOne
    {
        return $this->hasOne(LoadingList::class, 'trip_id');
    }

    public function podRecords()
    {
        return $this->hasMany(PodRecord::class, 'trip_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'dispatch_trip_id');
    }
}
