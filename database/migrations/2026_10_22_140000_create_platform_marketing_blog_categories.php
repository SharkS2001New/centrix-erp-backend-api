<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_marketing_blog_categories')) {
            Schema::create('platform_marketing_blog_categories', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 40)->unique();
                $table->string('label', 80);
                $table->unsignedSmallInteger('sort_order')->default(100);
                $table->timestamps();
            });
        }

        $now = now();
        $defaults = [
            ['slug' => 'general', 'label' => 'General', 'sort_order' => 10],
            ['slug' => 'product', 'label' => 'Product', 'sort_order' => 20],
            ['slug' => 'guides', 'label' => 'Guides', 'sort_order' => 30],
            ['slug' => 'announcements', 'label' => 'Announcements', 'sort_order' => 40],
            ['slug' => 'industry', 'label' => 'Industry', 'sort_order' => 50],
        ];
        foreach ($defaults as $row) {
            $exists = DB::table('platform_marketing_blog_categories')->where('slug', $row['slug'])->exists();
            if (! $exists) {
                DB::table('platform_marketing_blog_categories')->insert([
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_marketing_blog_categories');
    }
};
