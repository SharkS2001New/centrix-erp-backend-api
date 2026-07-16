<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalityFolioPayment extends Model
{
    protected $table = 'hospitality_folio_payments';

    protected $fillable = [
        'organization_id',
        'folio_id',
        'payment_method_id',
        'method_code',
        'amount',
        'reference',
        'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function folio(): BelongsTo
    {
        return $this->belongsTo(HospitalityFolio::class, 'folio_id');
    }
}
