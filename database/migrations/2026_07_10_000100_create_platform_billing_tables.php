<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_plans')) {
            Schema::create('platform_plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->nullable()->unique();
                $table->text('description')->nullable();
                $table->string('interval', 20)->default('monthly');
                $table->string('license_basis', 20)->default('org');
                $table->decimal('price', 14, 2)->default(0);
                $table->decimal('first_payment_price', 14, 2)->nullable();
                $table->decimal('renewal_price', 14, 2)->nullable();
                $table->string('currency', 8)->default('KES');
                $table->unsignedInteger('seat_limit')->nullable();
                $table->json('workspace_keys')->nullable();
                $table->json('module_keys')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('auto_invoice_template_id')->nullable();
                $table->timestamps();
            });
        }

        Schema::dropIfExists('platform_subscriptions');
        Schema::create('platform_subscriptions', function (Blueprint $table) {
            $table->id();
            // organizations.id is INT (legacy), not BIGINT
            $table->integer('organization_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('status', 30)->default('active');
            $table->unsignedInteger('seat_count')->default(1);
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->boolean('is_trial')->default(false);
            $table->date('trial_ends_at')->nullable();
            $table->decimal('first_payment_price', 14, 2)->nullable();
            $table->decimal('renewal_price', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 8)->default('KES');
            $table->string('license_basis', 20)->default('org');
            $table->json('workspace_keys')->nullable();
            $table->json('module_keys')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->timestamps();
            $table->unique('organization_id');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('platform_plans')->nullOnDelete();
        });

        Schema::dropIfExists('platform_contracts');
        Schema::create('platform_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->default('quote');
            $table->string('status', 20)->default('draft');
            $table->integer('organization_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('title');
            $table->string('reference')->nullable();
            $table->date('valid_until')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('currency', 8)->default('KES');
            $table->string('license_basis', 20)->default('org');
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('first_payment_price', 14, 2)->nullable();
            $table->decimal('renewal_price', 14, 2)->nullable();
            $table->unsignedInteger('seat_count')->default(1);
            $table->json('workspace_keys')->nullable();
            $table->json('module_keys')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            $table->text('customer_address')->nullable();
            $table->string('customer_tax_pin')->nullable();
            $table->longText('terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('plan_id')->references('id')->on('platform_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_contracts');
        Schema::dropIfExists('platform_subscriptions');
        Schema::dropIfExists('platform_plans');
    }
};
