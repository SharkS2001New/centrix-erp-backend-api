<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('platform_marketing_portfolios', 'template_key')) {
            Schema::table('platform_marketing_portfolios', function (Blueprint $table) {
                $table->string('template_key', 32)->default('modern')->after('vertical');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_marketing_portfolios', 'template_key')) {
            Schema::table('platform_marketing_portfolios', function (Blueprint $table) {
                $table->dropColumn('template_key');
            });
        }
    }
};
