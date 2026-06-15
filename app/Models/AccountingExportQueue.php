<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingExportQueue extends Model
{
    protected $table = 'accounting_export_queue';

    protected $fillable = [
        'organization_id',
        'provider',
        'entry_number',
        'entry_date',
        'reference_type',
        'reference_id',
        'description',
        'lines',
        'status',
        'external_journal_id',
        'last_error',
        'exported_at',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'lines' => 'array',
        'exported_at' => 'datetime',
    ];
}
