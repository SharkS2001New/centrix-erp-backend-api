<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PlatformMarketingBlogCategory extends Model
{
    protected $fillable = ['slug', 'label', 'sort_order'];

    public static function uniqueSlug(string $label, ?int $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($label, 36, '')) ?: 'category';
        $slug = $base;
        $i = 2;
        while (
            static::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return substr($slug, 0, 40);
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return static::query()->orderBy('sort_order')->orderBy('label')->pluck('slug')->all();
    }

    /** @return list<array{id: string, label: string}> */
    public static function options(): array
    {
        return static::query()
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['slug', 'label'])
            ->map(fn ($row) => ['id' => $row->slug, 'label' => $row->label])
            ->values()
            ->all();
    }
}
