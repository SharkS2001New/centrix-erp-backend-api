<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_device_tokens')) {
            return;
        }

        if (! Schema::hasColumn('user_device_tokens', 'organization_id')) {
            Schema::table('user_device_tokens', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('user_id');
                $table->index(['organization_id', 'app_channel'], 'user_device_tokens_org_channel_index');
            });
        }

        DB::table('user_device_tokens')
            ->join('users', 'users.id', '=', 'user_device_tokens.user_id')
            ->whereNull('user_device_tokens.organization_id')
            ->update([
                'user_device_tokens.organization_id' => DB::raw('users.organization_id'),
            ]);

        $column = collect(Schema::getColumns('user_device_tokens'))
            ->firstWhere('name', 'organization_id');

        if ($column && ($column['nullable'] ?? true)) {
            Schema::table('user_device_tokens', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_device_tokens') || ! Schema::hasColumn('user_device_tokens', 'organization_id')) {
            return;
        }

        Schema::table('user_device_tokens', function (Blueprint $table) {
            $table->dropIndex('user_device_tokens_org_channel_index');
            $table->dropColumn('organization_id');
        });
    }
};
