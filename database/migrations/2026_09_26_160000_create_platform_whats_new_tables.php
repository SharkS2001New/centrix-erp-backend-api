<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform → What's new: super-admin release notes with org + workspace targeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_whats_new_notes')) {
            Schema::create('platform_whats_new_notes', function (Blueprint $table) {
                $table->id();
                $table->string('title', 200);
                $table->text('body');
                $table->string('link_url', 500)->nullable();
                /** all_users | admins_only */
                $table->string('audience', 32)->default('all_users');
                /** Empty / null = every tenant org (excludes PLATFORM). */
                $table->json('organization_ids')->nullable();
                /** Workspace keys from config/erp_workspaces.php (pos, backoffice, …). */
                $table->json('workspace_ids');
                /** draft | published */
                $table->string('status', 16)->default('draft')->index();
                $table->boolean('show_on_login')->default(true);
                $table->timestamp('published_at')->nullable()->index();
                $table->unsignedBigInteger('published_by')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedInteger('notified_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('platform_whats_new_reads')) {
            Schema::create('platform_whats_new_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('note_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('organization_id')->index();
                $table->timestamp('dismissed_at');
                $table->timestamps();

                $table->unique(['note_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_whats_new_reads');
        Schema::dropIfExists('platform_whats_new_notes');
    }
};
