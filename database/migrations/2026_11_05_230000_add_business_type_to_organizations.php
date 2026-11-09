<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations') || Schema::hasColumn('organizations', 'business_type')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('business_type', 100)->nullable()->after('org_name');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('organizations') && Schema::hasColumn('organizations', 'business_type')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('business_type');
            });
        }
    }
};
