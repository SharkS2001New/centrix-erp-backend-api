<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equity paybill / collection accounts (multi-org, route-mappable).
 * primary_account_number is globally unique so callbacks can resolve the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('equity_bank_accounts')) {
            Schema::create('equity_bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('name', 120);
                /** Paybill / merchant / collection account Equity sends on callbacks. */
                $table->string('primary_account_number', 40);
                $table->string('account_number', 40)->nullable();
                $table->string('paybill_number', 40)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('route_id')->nullable()->index();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique('primary_account_number');
                $table->index(['organization_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('equity_incoming_payments')) {
            Schema::create('equity_incoming_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->unsignedBigInteger('equity_bank_account_id')->nullable()->index();
                $table->unsignedBigInteger('matched_branch_id')->nullable()->index();
                $table->unsignedBigInteger('matched_route_id')->nullable()->index();
                $table->string('transaction_id', 80);
                $table->string('phone_number', 32)->nullable();
                $table->string('bill_ref_number', 120)->nullable();
                $table->string('payer_name', 160)->nullable();
                $table->string('business_account_number', 40)->nullable();
                $table->unsignedInteger('parsed_order_num')->nullable();
                $table->unsignedInteger('parsed_customer_num')->nullable();
                $table->unsignedInteger('amount');
                $table->unsignedInteger('applied_amount')->nullable();
                $table->string('source', 40)->default('callback');
                $table->string('status', 20)->default('available');
                $table->unsignedBigInteger('applied_sale_id')->nullable()->index();
                $table->unsignedBigInteger('applied_invoice_id')->nullable();
                $table->string('match_method', 40)->nullable();
                $table->string('match_confidence', 20)->nullable();
                $table->string('reconciliation_status', 20)->default('unmatched');
                $table->unsignedBigInteger('matched_by_user_id')->nullable();
                $table->text('reconciliation_notes')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('matched_at')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'transaction_id'], 'equity_in_org_txn_unique');
                $table->index(
                    ['organization_id', 'status', 'reconciliation_status'],
                    'equity_in_org_status_recon_idx',
                );
            });
        }

        if (Schema::hasTable('routes') && ! Schema::hasColumn('routes', 'equity_bank_account_id')) {
            Schema::table('routes', function (Blueprint $table) {
                $table->unsignedBigInteger('equity_bank_account_id')->nullable()->after('mpesa_paybill_account_id');
                $table->index('equity_bank_account_id');
            });
        }

        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'equity_bank_account_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $after = Schema::hasColumn('branches', 'mpesa_paybill_account_id')
                    ? 'mpesa_paybill_account_id'
                    : 'settings';
                $table->unsignedBigInteger('equity_bank_account_id')->nullable()->after($after);
                $table->index('equity_bank_account_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('routes') && Schema::hasColumn('routes', 'equity_bank_account_id')) {
            Schema::table('routes', function (Blueprint $table) {
                $table->dropColumn('equity_bank_account_id');
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'equity_bank_account_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('equity_bank_account_id');
            });
        }

        Schema::dropIfExists('equity_incoming_payments');
        Schema::dropIfExists('equity_bank_accounts');
    }
};
