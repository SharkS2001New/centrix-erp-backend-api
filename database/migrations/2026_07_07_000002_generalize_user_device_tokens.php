<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_device_tokens', function (Blueprint $table) {
            $table->string('app_channel', 24)->default('manager')->after('user_id');
        });

        Schema::table('manager_device_tokens', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'token']);
        });

        Schema::rename('manager_device_tokens', 'user_device_tokens');

        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->unique(['user_id', 'token', 'app_channel'], 'user_device_tokens_user_token_channel_unique');
            $table->index(['user_id', 'app_channel'], 'user_device_tokens_user_channel_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->dropUnique('user_device_tokens_user_token_channel_unique');
            $table->dropIndex('user_device_tokens_user_channel_index');
        });

        Schema::rename('user_device_tokens', 'manager_device_tokens');

        Schema::table('manager_device_tokens', function (Blueprint $table) {
            $table->unique(['user_id', 'token']);
            $table->dropColumn('app_channel');
        });
    }
};
