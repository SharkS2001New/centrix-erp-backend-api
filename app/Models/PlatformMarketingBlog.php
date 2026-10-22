<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformMarketingBlog extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const CATEGORIES = [
        'general',
        'product',
        'guides',
        'announcements',
        'industry',
    ];

    /** @return list<string> */
    public static function allowedCategorySlugs(): array
    {
        if (Schema::hasTable('platform_marketing_blog_categories')) {
            $slugs = PlatformMarketingBlogCategory::slugs();
            if ($slugs !== []) {
                return $slugs;
            }
        }

        return self::CATEGORIES;
    }

    /** @return list<array{id: string, label: string}> */
    public static function categoryOptions(): array
    {
        if (Schema::hasTable('platform_marketing_blog_categories')) {
            $options = PlatformMarketingBlogCategory::options();
            if ($options !== []) {
                return $options;
            }
        }

        return array_map(
            fn (string $id) => ['id' => $id, 'label' => Str::title(str_replace('_', ' ', $id))],
            self::CATEGORIES,
        );
    }

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'cover_image_url',
        'category',
        'status',
        'author_name',
        'read_time',
        'seo_title',
        'seo_description',
        'published_at',
        'published_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'read_time' => 'integer',
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopePublished($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
