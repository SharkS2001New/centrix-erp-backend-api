<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'login_channels')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('login_channels')->nullable()->after('is_mobile_user');
            });
        }

        if (Schema::hasTable('users')) {
            DB::table('users')
                ->where('is_mobile_user', 1)
                ->whereNull('login_channels')
                ->update(['login_channels' => json_encode(['mobile'])]);

            DB::table('users')
                ->where(function ($query) {
                    $query->where('is_mobile_user', 0)->orWhereNull('is_mobile_user');
                })
                ->whereNull('login_channels')
                ->update(['login_channels' => json_encode(['backoffice', 'pos', 'mobile'])]);
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        if (! Schema::hasColumn('personal_access_tokens', 'login_channel')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('login_channel', 20)->default('backoffice')->after('user_membership_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'login_channel')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('login_channel');
            });
        }

        if (Schema::hasColumn('users', 'login_channels')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('login_channels');
            });
        }
    }
};
