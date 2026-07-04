<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('in_app_notifications', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('resolved_at');
            $table->index(['user_id', 'dismissed_at'], 'idx_in_app_notifications_user_dismissed');
        });
    }

    public function down(): void
    {
        Schema::table('in_app_notifications', function (Blueprint $table) {
            $table->dropIndex('idx_in_app_notifications_user_dismissed');
            $table->dropColumn('dismissed_at');
        });
    }
};
