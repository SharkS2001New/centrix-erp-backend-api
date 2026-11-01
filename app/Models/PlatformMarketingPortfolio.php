<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PlatformMarketingPortfolio extends Model
{
    protected $fillable = [
        'title', 'status', 'industry', 'vertical', 'template_key', 'font_family',
        'prospect_name', 'prospect_contact', 'prospect_email', 'prospect_phone',
        'business_name', 'location', 'business_size', 'branch_count', 'till_count',
        'pain_points', 'focus_modules', 'tone', 'hero_image_key',
        'sections', 'ai_brief', 'share_token', 'shared_at',
        'created_by', 'organization_id', 'quote_id',
    ];

    protected $casts = [
        'focus_modules' => 'array',
        'sections' => 'array',
        'shared_at' => 'datetime',
        'branch_count' => 'integer',
        'till_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function ensureShareToken(): string
    {
        if ($this->share_token) {
            if ($this->status !== 'shared') {
                $this->forceFill([
                    'status' => 'shared',
                    'shared_at' => $this->shared_at ?: now(),
                ])->save();
            }

            return $this->share_token;
        }

        $this->forceFill([
            'share_token' => Str::lower(Str::random(40)),
            'shared_at' => now(),
            'status' => 'shared',
        ])->save();

        return (string) $this->share_token;
    }

    public function toPublicArray(): array
    {
        return [
            'title' => $this->title,
            'industry' => $this->industry,
            'vertical' => $this->vertical,
            'template_key' => $this->template_key ?: 'modern',
            'font_family' => $this->font_family ?: 'sans',
            'prospect_name' => $this->prospect_name,
            'business_name' => $this->business_name,
            'location' => $this->location,
            'hero_image_key' => $this->hero_image_key,
            'sections' => collect($this->sections ?? [])->map(function (array $section): array {
                if (! empty($section['image_path'])) {
                    $section['image_url'] = \Illuminate\Support\Facades\Storage::disk('public')->url($section['image_path']);
                }

                return $section;
            })->all(),
            'shared_at' => $this->shared_at?->toIso8601String(),
        ];
    }
}
