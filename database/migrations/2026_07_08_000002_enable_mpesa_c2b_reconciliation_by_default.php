<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->each(function (Organization $org) {
            $settings = $org->module_settings ?? [];
            $finance = is_array($settings['finance'] ?? null) ? $settings['finance'] : [];
            $mpesa = is_array($finance['mpesa'] ?? null) ? $finance['mpesa'] : [];

            if (filter_var($mpesa['enable_c2b_reconciliation'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return;
            }

            $mpesa['enable_c2b_reconciliation'] = true;
            $finance['mpesa'] = $mpesa;
            $settings['finance'] = $finance;

            $org->update(['module_settings' => $settings]);
        });
    }

    public function down(): void
    {
        // Non-destructive rollout — no rollback.
    }
};
