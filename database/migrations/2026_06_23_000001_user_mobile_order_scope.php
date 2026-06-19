<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'mobile_order_scope')) {
                $table->enum('mobile_order_scope', ['route_only', 'normal_only', 'both'])
                    ->nullable()
                    ->default('both')
                    ->after('login_channels');
            }
            if (! Schema::hasColumn('users', 'assigned_route_id')) {
                $table->unsignedInteger('assigned_route_id')->nullable()->after('mobile_order_scope');
                $table->foreign('assigned_route_id')->references('id')->on('routes')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('customers', 'customer_type')) {
            DB::statement("ALTER TABLE customers MODIFY customer_type ENUM('debtor','route','regular') NOT NULL DEFAULT 'debtor'");
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'assigned_route_id')) {
                $table->dropForeign(['assigned_route_id']);
                $table->dropColumn('assigned_route_id');
            }
            if (Schema::hasColumn('users', 'mobile_order_scope')) {
                $table->dropColumn('mobile_order_scope');
            }
        });
    }
};
