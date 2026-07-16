<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityFolioCharge extends Model
{
    protected $table = 'hospitality_folio_charges';

    protected $fillable = [
        'organization_id',
        'folio_id',
        'check_id',
        'charge_type',
        'description',
        'amount',
        'vat_amount',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function folio(): BelongsTo
    {
        return $this->belongsTo(HospitalityFolio::class, 'folio_id');
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(HospitalityCheck::class, 'check_id');
    }
}
