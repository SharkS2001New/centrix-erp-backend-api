<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'email')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email', 200)->nullable()->after('additional_phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'email')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
