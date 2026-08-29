<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_accounts')) {
            return;
        }

        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('provider', 40);
            $table->string('account_type', 40);
            $table->string('account_name', 160);
            $table->string('account_number', 80)->nullable();
            $table->string('shortcode', 40)->nullable();
            $table->string('provider_account_type', 80);
            $table->unsignedBigInteger('provider_account_id');
            $table->string('status', 24)->default('active');
            $table->boolean('is_default')->default(false);
            $table->boolean('auto_match_payments')->default(true);
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'provider', 'provider_account_type', 'provider_account_id'],
                'payment_accounts_provider_unique',
            );
            $table->index(['organization_id', 'provider', 'status']);
            $table->index(['organization_id', 'branch_id', 'provider']);
        });

        $this->backfillPaymentAccounts();
    }

    protected function backfillPaymentAccounts(): void
    {
        if (! class_exists(\App\Services\Payments\PaymentAccountService::class)) {
            return;
        }

        $service = app(\App\Services\Payments\PaymentAccountService::class);
        $orgIds = [];

        if (Schema::hasTable('mpesa_paybill_accounts')) {
            $orgIds = array_merge(
                $orgIds,
                \Illuminate\Support\Facades\DB::table('mpesa_paybill_accounts')
                    ->distinct()
                    ->pluck('organization_id')
                    ->all(),
            );
        }
        if (Schema::hasTable('equity_bank_accounts')) {
            $orgIds = array_merge(
                $orgIds,
                \Illuminate\Support\Facades\DB::table('equity_bank_accounts')
                    ->distinct()
                    ->pluck('organization_id')
                    ->all(),
            );
        }

        foreach (array_unique(array_map('intval', $orgIds)) as $orgId) {
            if ($orgId > 0) {
                $service->syncOrganization($orgId);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
