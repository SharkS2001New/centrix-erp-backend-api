<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform → Marketing blogs: posts published to the CentrixERP public website Resources page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_marketing_blogs')) {
            return;
        }

        Schema::create('platform_marketing_blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->string('excerpt', 500)->nullable();
            $table->longText('body');
            $table->string('cover_image_url', 1000)->nullable();
            /** product | guides | announcements | industry | general */
            $table->string('category', 40)->default('general')->index();
            /** draft | published */
            $table->string('status', 16)->default('draft')->index();
            $table->string('author_name', 120)->nullable();
            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 320)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_marketing_blogs');
    }
};
