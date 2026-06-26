<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations') || Schema::hasColumn('organizations', 'company_code_aliases')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->json('company_code_aliases')->nullable()->after('company_code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations') || ! Schema::hasColumn('organizations', 'company_code_aliases')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('company_code_aliases');
        });
    }
};
