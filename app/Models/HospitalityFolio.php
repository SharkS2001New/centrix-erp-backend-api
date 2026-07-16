<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalityFolio extends Model
{
    protected $table = 'hospitality_folios';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'room_id',
        'folio_number',
        'guest_name',
        'guest_phone',
        'status',
        'checked_in_at',
        'checked_out_at',
        'opened_by',
        'balance',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'balance' => 'decimal:2',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(HospitalityRoom::class, 'room_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(HospitalityFolioCharge::class, 'folio_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(HospitalityFolioPayment::class, 'folio_id');
    }
}
