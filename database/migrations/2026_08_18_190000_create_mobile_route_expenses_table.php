<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mobile_route_expenses')) {
            return;
        }

        Schema::create('mobile_route_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->date('expense_date');
            $table->string('description', 200);
            $table->decimal('expense_amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 200)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'idx_mre_org_status');
            $table->index(['organization_id', 'user_id', 'expense_date'], 'idx_mre_org_user_date');
            $table->index(['organization_id', 'expense_date'], 'idx_mre_org_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_route_expenses');
    }
};
