<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_billing_settings')) {
            Schema::table('platform_billing_settings', function (Blueprint $table) {
                foreach ([
                    'equity_manual_paybill',
                    'equity_manual_account_name',
                    'equity_manual_account_number',
                    'equity_manual_instructions',
                ] as $column) {
                    if (! Schema::hasColumn('platform_billing_settings', $column)) {
                        $table->string($column, 1000)->nullable();
                    }
                }
            });
        }

        if (Schema::hasTable('platform_licence_checkouts')) {
            Schema::table('platform_licence_checkouts', function (Blueprint $table) {
                if (! Schema::hasColumn('platform_licence_checkouts', 'manual_payment_reference')) {
                    $table->string('manual_payment_reference', 100)->nullable()->unique();
                }
                if (! Schema::hasColumn('platform_licence_checkouts', 'manual_payment_notified_at')) {
                    $table->timestamp('manual_payment_notified_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('platform_billing_settings')) {
            Schema::table('platform_billing_settings', function (Blueprint $table) {
                $columns = [
                    'equity_manual_paybill',
                    'equity_manual_account_name',
                    'equity_manual_account_number',
                    'equity_manual_instructions',
                ];
                $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('platform_billing_settings', $column));
                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }

        if (Schema::hasTable('platform_licence_checkouts')) {
            Schema::table('platform_licence_checkouts', function (Blueprint $table) {
                if (Schema::hasColumn('platform_licence_checkouts', 'manual_payment_reference')) {
                    $table->dropUnique(['manual_payment_reference']);
                    $table->dropColumn('manual_payment_reference');
                }
                if (Schema::hasColumn('platform_licence_checkouts', 'manual_payment_notified_at')) {
                    $table->dropColumn('manual_payment_notified_at');
                }
            });
        }
    }
};
