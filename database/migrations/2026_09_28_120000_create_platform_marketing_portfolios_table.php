<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_marketing_portfolios')) {
            return;
        }

        // users.id / organizations.id are signed INT in this schema (not unsignedBigInteger).
        Schema::create('platform_marketing_portfolios', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('status', 32)->default('draft'); // draft | ready | shared
            $table->string('industry', 40)->default('commerce');
            $table->string('vertical', 64)->default('small_shop');
            $table->string('prospect_name', 200);
            $table->string('prospect_contact', 200)->nullable();
            $table->string('prospect_email', 200)->nullable();
            $table->string('prospect_phone', 64)->nullable();
            $table->string('business_name', 200)->nullable();
            $table->string('location', 200)->nullable();
            $table->string('business_size', 120)->nullable();
            $table->unsignedTinyInteger('branch_count')->nullable();
            $table->unsignedTinyInteger('till_count')->nullable();
            $table->text('pain_points')->nullable();
            $table->json('focus_modules')->nullable();
            $table->string('tone', 40)->default('professional'); // professional | friendly | executive
            $table->string('hero_image_key', 80)->nullable();
            $table->json('sections')->nullable();
            $table->text('ai_brief')->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('organization_id')->nullable();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index(['status', 'vertical']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_marketing_portfolios');
    }
};
