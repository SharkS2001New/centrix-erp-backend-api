<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDeviceToken extends Model
{
    public const CHANNEL_MANAGER = 'manager';

    public const CHANNEL_MOBILE_SALES = 'mobile_sales';

    protected $table = 'user_device_tokens';

    protected $fillable = [
        'user_id',
        'organization_id',
        'app_channel',
        'token',
        'platform',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
