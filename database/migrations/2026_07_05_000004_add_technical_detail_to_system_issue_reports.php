<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_issue_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('system_issue_reports', 'technical_detail')) {
                $table->mediumText('technical_detail')->nullable()->after('message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('system_issue_reports', function (Blueprint $table) {
            if (Schema::hasColumn('system_issue_reports', 'technical_detail')) {
                $table->dropColumn('technical_detail');
            }
        });
    }
};
