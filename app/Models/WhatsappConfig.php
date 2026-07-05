<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConfig extends Model
{
    protected $table = 'whatsapp_configs';

    protected $fillable = [
        'organization_id',
        'branch_id',
        'bot_user_id',
        'phone_number_id',
        'waba_id',
        'display_phone',
        'access_token',
        'webhook_verify_token',
        'graph_api_version',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bot_user_id');
    }
}
