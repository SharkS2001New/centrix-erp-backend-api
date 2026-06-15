<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->integer('organization_id')->nullable()->after('tokenable_type');
            $table->unsignedBigInteger('user_membership_id')->nullable()->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['organization_id', 'user_membership_id']);
        });
    }
};
