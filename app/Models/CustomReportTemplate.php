<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomReportTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'created_by',
        'name',
        'description',
        'spec',
        'is_shared',
    ];

    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
