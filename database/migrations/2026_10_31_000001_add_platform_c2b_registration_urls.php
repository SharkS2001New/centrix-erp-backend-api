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
            foreach (['c2b_confirmation_url', 'c2b_validation_url'] as $column) {
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
            $columns = array_filter(
                ['c2b_confirmation_url', 'c2b_validation_url'],
                fn (string $column) => Schema::hasColumn('platform_billing_settings', $column),
            );
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
