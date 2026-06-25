<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'password_changed_at')) {
                    $table->timestamp('password_changed_at')->nullable()->after('password');
                }
                if (! Schema::hasColumn('users', 'password_expiry_skip_count')) {
                    $table->unsignedTinyInteger('password_expiry_skip_count')->default(0)->after('must_change_password');
                }
            });

            if (Schema::hasColumn('users', 'password_changed_at')) {
                DB::table('users')
                    ->whereNull('password_changed_at')
                    ->update([
                        'password_changed_at' => DB::raw('COALESCE(last_login, created_at, NOW())'),
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'password_expiry_skip_count')) {
                $table->dropColumn('password_expiry_skip_count');
            }
            if (Schema::hasColumn('users', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }
        });
    }
};
