<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Investor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'investor_code',
        'investor_name',
        'contact_person',
        'phone',
        'email',
        'notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function contributions(): HasMany
    {
        return $this->hasMany(InvestorContribution::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InvestorProductBatch::class);
    }

    public function spendLinks(): HasMany
    {
        return $this->hasMany(InvestorSpendLink::class);
    }

    public static function generateNextCode(int $organizationId): string
    {
        $codes = static::query()
            ->where('organization_id', $organizationId)
            ->withTrashed()
            ->pluck('investor_code');

        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^INV-(\d+)$/i', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'INV-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
