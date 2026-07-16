<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** F&B / bar check — separate from TemporaryCart / Sale. */
class HospitalityCheck extends Model
{
    protected $table = 'hospitality_checks';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'outlet_id',
        'floor_table_id',
        'folio_id',
        'check_number',
        'status',
        'service_mode',
        'opened_by',
        'closed_by',
        'subtotal',
        'vat_total',
        'service_charge',
        'total',
        'amount_paid',
        'opened_at',
        'closed_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'vat_total' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(HospitalityOutlet::class, 'outlet_id');
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(HospitalityFolio::class, 'folio_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(HospitalityCheckLine::class, 'check_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(HospitalityCheckPayment::class, 'check_id');
    }
}
