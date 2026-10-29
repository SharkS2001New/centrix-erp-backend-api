<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_billing_settings')) {
            return;
        }

        Schema::table('platform_billing_settings', function (Blueprint $table) {
            foreach ([
                'manual_mpesa_account_name',
                'manual_mpesa_instructions',
                'stk_env',
                'stk_consumer_key',
                'stk_consumer_secret',
                'stk_shortcode',
                'stk_passkey',
                'stk_callback_url',
            ] as $column) {
                if (! Schema::hasColumn('platform_billing_settings', $column)) {
                    $table->text($column)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_billing_settings')) {
            return;
        }

        Schema::table('platform_billing_settings', function (Blueprint $table) {
            $columns = [
                'manual_mpesa_account_name',
                'manual_mpesa_instructions',
                'stk_env',
                'stk_consumer_key',
                'stk_consumer_secret',
                'stk_shortcode',
                'stk_passkey',
                'stk_callback_url',
            ];
            $existing = array_filter($columns, fn (string $column) => Schema::hasColumn('platform_billing_settings', $column));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
