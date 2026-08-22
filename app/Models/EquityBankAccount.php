<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquityBankAccount extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'primary_account_number',
        'account_number',
        'paybill_number',
        'branch_id',
        'route_id',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(RouteModel::class, 'route_id');
    }

    /** @return list<string> */
    public function allAccountNumbers(): array
    {
        $codes = [];
        foreach ([$this->primary_account_number, $this->paybill_number, $this->account_number] as $code) {
            $value = trim((string) $code);
            if ($value !== '' && ! in_array($value, $codes, true)) {
                $codes[] = $value;
            }
        }

        return $codes;
    }

    public function matchesAccountNumber(string $accountNumber): bool
    {
        $needle = trim($accountNumber);

        return $needle !== '' && in_array($needle, $this->allAccountNumbers(), true);
    }
}
