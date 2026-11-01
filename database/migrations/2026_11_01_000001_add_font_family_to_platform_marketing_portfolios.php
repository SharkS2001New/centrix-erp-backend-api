<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_marketing_portfolios')
            && ! Schema::hasColumn('platform_marketing_portfolios', 'font_family')) {
            Schema::table('platform_marketing_portfolios', function (Blueprint $table) {
                $table->string('font_family', 24)->default('sans')->after('template_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_marketing_portfolios')
            && Schema::hasColumn('platform_marketing_portfolios', 'font_family')) {
            Schema::table('platform_marketing_portfolios', function (Blueprint $table) {
                $table->dropColumn('font_family');
            });
        }
    }
};
