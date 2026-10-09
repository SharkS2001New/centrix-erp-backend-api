<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_plans')) {
            Schema::table('platform_plans', function (Blueprint $table) {
                if (! Schema::hasColumn('platform_plans', 'feature_details')) {
                    $table->json('feature_details')->nullable();
                }
                if (! Schema::hasColumn('platform_plans', 'section_keys')) {
                    $table->json('section_keys')->nullable();
                }
            });
        }

        if (Schema::hasTable('platform_billing_settings')) {
            Schema::table('platform_billing_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('platform_billing_settings', 'duration_discount_percent')) {
                    $table->decimal('duration_discount_percent', 5, 2)->default(0);
                }
                if (! Schema::hasColumn('platform_billing_settings', 'duration_discount_min_months')) {
                    $table->unsignedSmallInteger('duration_discount_min_months')->default(3);
                }
            });
        }

        if (! Schema::hasTable('platform_licence_checkouts')) {
            Schema::create('platform_licence_checkouts', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->unsignedBigInteger('plan_id')->nullable();
                $table->unsignedSmallInteger('months')->default(1);
                $table->decimal('amount', 14, 2);
                $table->decimal('discount_percent', 5, 2)->default(0);
                $table->string('phone_number', 30)->nullable();
                $table->string('checkout_request_id', 80)->nullable();
                $table->string('mpesa_receipt', 40)->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamps();
                $table->index(['organization_id', 'status']);
                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_licence_checkouts');
    }
};
