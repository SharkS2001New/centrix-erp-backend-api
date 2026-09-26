<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformWhatsNewNote extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const AUDIENCE_ALL_USERS = 'all_users';

    public const AUDIENCE_ADMINS_ONLY = 'admins_only';

    protected $fillable = [
        'title',
        'body',
        'link_url',
        'audience',
        'organization_ids',
        'workspace_ids',
        'status',
        'show_on_login',
        'published_at',
        'published_by',
        'created_by',
        'notified_count',
    ];

    protected function casts(): array
    {
        return [
            'organization_ids' => 'array',
            'workspace_ids' => 'array',
            'show_on_login' => 'boolean',
            'published_at' => 'datetime',
            'notified_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(PlatformWhatsNewRead::class, 'note_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** @return list<int> */
    public function targetedOrganizationIds(): array
    {
        $ids = $this->organization_ids ?? [];
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /** @return list<string> */
    public function targetedWorkspaceIds(): array
    {
        $ids = $this->workspace_ids ?? [];
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn ($id) => strtolower(trim((string) $id)),
            array_filter($ids, fn ($id) => is_string($id) || is_numeric($id)),
        )));
    }

    public function targetsAllOrganizations(): bool
    {
        return $this->targetedOrganizationIds() === [];
    }

    public function isAdminsOnly(): bool
    {
        return $this->audience === self::AUDIENCE_ADMINS_ONLY;
    }
}
