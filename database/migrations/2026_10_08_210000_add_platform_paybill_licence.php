<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_plans')) {
            Schema::table('platform_plans', function (Blueprint $table) {
                if (! Schema::hasColumn('platform_plans', 'monthly_price')) {
                    $table->decimal('monthly_price', 14, 2)->nullable()->after('renewal_price');
                }
                if (! Schema::hasColumn('platform_plans', 'yearly_price')) {
                    $table->decimal('yearly_price', 14, 2)->nullable()->after('monthly_price');
                }
                if (! Schema::hasColumn('platform_plans', 'token_pack_price')) {
                    $table->decimal('token_pack_price', 14, 2)->nullable()->after('yearly_price');
                }
                if (! Schema::hasColumn('platform_plans', 'token_pack_days')) {
                    $table->unsignedInteger('token_pack_days')->nullable()->after('token_pack_price');
                }
            });

            $this->seedPricesFromRenewal();
        }

        if (! Schema::hasTable('platform_billing_settings')) {
            Schema::create('platform_billing_settings', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id')->nullable();
                $table->string('short_code', 20)->nullable();
                $table->string('account_name_prefix', 40)->nullable();
                $table->timestamps();
                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('platform_licence_balances')) {
            Schema::create('platform_licence_balances', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id');
                $table->decimal('balance', 14, 2)->default(0);
                $table->timestamps();
                $table->unique('organization_id');
                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('platform_licence_payments')) {
            Schema::create('platform_licence_payments', function (Blueprint $table) {
                $table->id();
                $table->integer('organization_id')->nullable();
                $table->string('mpesa_transaction_id', 64)->unique();
                $table->string('phone_number', 30)->nullable();
                $table->decimal('amount', 14, 2);
                $table->string('bill_ref_number', 120)->nullable();
                $table->string('business_short_code', 20)->nullable();
                $table->string('account_reference', 80)->nullable();
                $table->string('status', 20)->default('unmatched');
                $table->string('match_method', 30)->nullable();
                $table->string('product', 20)->nullable();
                $table->unsignedInteger('days_added')->nullable();
                $table->decimal('amount_applied', 14, 2)->nullable();
                $table->decimal('balance_after', 14, 2)->nullable();
                $table->date('period_end_before')->nullable();
                $table->date('period_end_after')->nullable();
                $table->string('note', 80)->nullable();
                $table->timestamps();
                $table->index('organization_id');
                $table->index('status');
                $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_licence_payments');
        Schema::dropIfExists('platform_licence_balances');
        Schema::dropIfExists('platform_billing_settings');

        if (Schema::hasTable('platform_plans')) {
            Schema::table('platform_plans', function (Blueprint $table) {
                foreach (['token_pack_days', 'token_pack_price', 'yearly_price', 'monthly_price'] as $column) {
                    if (Schema::hasColumn('platform_plans', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    protected function seedPricesFromRenewal(): void
    {
        if (! Schema::hasColumn('platform_plans', 'monthly_price') || ! Schema::hasColumn('platform_plans', 'yearly_price')) {
            return;
        }

        foreach (DB::table('platform_plans')->orderBy('id')->get() as $plan) {
            $renewal = $plan->renewal_price ?? $plan->price;
            if ($renewal === null) {
                continue;
            }

            $interval = strtolower(trim((string) ($plan->interval ?? 'monthly')));
            $updates = [];
            if (in_array($interval, ['annual', 'yearly', 'year'], true)) {
                if ($plan->yearly_price === null) {
                    $updates['yearly_price'] = $renewal;
                }
            } elseif ($plan->monthly_price === null) {
                $updates['monthly_price'] = $renewal;
            }

            if ($updates !== []) {
                DB::table('platform_plans')->where('id', $plan->id)->update($updates);
            }
        }
    }
};
