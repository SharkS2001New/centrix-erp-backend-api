<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        if (! Schema::hasColumn('personal_access_tokens', 'organization_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->integer('organization_id')->nullable()->after('tokenable_type');
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'user_membership_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->unsignedBigInteger('user_membership_id')->nullable()->after('organization_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        $drops = array_values(array_filter(
            ['organization_id', 'user_membership_id'],
            fn (string $col) => Schema::hasColumn('personal_access_tokens', $col),
        ));

        if ($drops !== []) {
            Schema::table('personal_access_tokens', function (Blueprint $table) use ($drops) {
                $table->dropColumn($drops);
            });
        }
    }
};
