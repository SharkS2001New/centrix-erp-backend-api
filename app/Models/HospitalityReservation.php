<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityReservation extends Model
{
    protected $table = 'hospitality_reservations';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'room_type_id',
        'room_id',
        'folio_id',
        'confirmation_code',
        'guest_name',
        'guest_phone',
        'arrival_date',
        'departure_date',
        'status',
        'deposit_amount',
    ];

    protected $casts = [
        'arrival_date' => 'date',
        'departure_date' => 'date',
        'deposit_amount' => 'decimal:2',
    ];

    public function folio(): BelongsTo
    {
        return $this->belongsTo(HospitalityFolio::class, 'folio_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HospitalityRoom::class, 'room_id');
    }
}
