<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PodRecord extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'sale_id',
        'trip_id',
        'captured_at',
        'captured_by',
        'recipient_name',
        'notes',
        'signature_path',
        'photo_path',
        'status',
        'gps_lat',
        'gps_lng',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'gps_lat' => 'float',
        'gps_lng' => 'float',
    ];

    protected $appends = ['photo_url', 'signature_url'];

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photo_path
            ? rtrim((string) config('app.url'), '/')."/api/v1/pod-records/{$this->id}/photo/file"
            : null);
    }

    protected function signatureUrl(): Attribute
    {
        return Attribute::get(fn () => $this->signature_path
            ? rtrim((string) config('app.url'), '/')."/api/v1/pod-records/{$this->id}/signature/file"
            : null);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(DispatchTrip::class, 'trip_id');
    }

    public function capturedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PodLine::class, 'pod_record_id');
    }
}
