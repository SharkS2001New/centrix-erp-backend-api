<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mpesa_paybill_accounts')) {
            Schema::create('mpesa_paybill_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->index();
                $table->string('name', 120);
                /** BusinessShortCode Safaricom sends on C2B (unique across tenants). */
                $table->string('primary_short_code', 20);
                $table->string('shortcode', 20)->nullable();
                $table->string('till_number', 20)->nullable();
                $table->string('child_storecode', 20)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('route_id')->nullable()->index();
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique('primary_short_code');
                $table->index(['organization_id', 'is_active']);
            });
        }

        if (Schema::hasTable('routes') && ! Schema::hasColumn('routes', 'mpesa_paybill_account_id')) {
            Schema::table('routes', function (Blueprint $table) {
                $table->unsignedBigInteger('mpesa_paybill_account_id')->nullable()->after('receipt_payment_details');
                $table->index('mpesa_paybill_account_id');
            });
        }

        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'mpesa_paybill_account_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->unsignedBigInteger('mpesa_paybill_account_id')->nullable()->after('settings');
                $table->index('mpesa_paybill_account_id');
            });
        }

        if (Schema::hasTable('mpesa_incoming_payments')
            && ! Schema::hasColumn('mpesa_incoming_payments', 'mpesa_paybill_account_id')) {
            Schema::table('mpesa_incoming_payments', function (Blueprint $table) {
                $table->unsignedBigInteger('mpesa_paybill_account_id')->nullable()->after('organization_id');
                $table->unsignedBigInteger('matched_branch_id')->nullable()->after('mpesa_paybill_account_id');
                $table->unsignedBigInteger('matched_route_id')->nullable()->after('matched_branch_id');
                $table->index('mpesa_paybill_account_id');
            });
        }

        $this->backfillFromExistingSettings();
    }

    public function down(): void
    {
        if (Schema::hasTable('mpesa_incoming_payments')) {
            Schema::table('mpesa_incoming_payments', function (Blueprint $table) {
                foreach (['mpesa_paybill_account_id', 'matched_branch_id', 'matched_route_id'] as $col) {
                    if (Schema::hasColumn('mpesa_incoming_payments', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('routes') && Schema::hasColumn('routes', 'mpesa_paybill_account_id')) {
            Schema::table('routes', function (Blueprint $table) {
                $table->dropColumn('mpesa_paybill_account_id');
            });
        }

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'mpesa_paybill_account_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('mpesa_paybill_account_id');
            });
        }

        Schema::dropIfExists('mpesa_paybill_accounts');
    }

    protected function backfillFromExistingSettings(): void
    {
        if (! Schema::hasTable('mpesa_paybill_accounts') || ! Schema::hasTable('organizations')) {
            return;
        }

        $orgs = DB::table('organizations')->select('id', 'module_settings')->get();
        foreach ($orgs as $org) {
            $settings = is_string($org->module_settings)
                ? json_decode($org->module_settings, true)
                : (is_array($org->module_settings) ? $org->module_settings : []);
            $mpesa = is_array($settings['finance']['mpesa'] ?? null) ? $settings['finance']['mpesa'] : [];
            $codes = $this->codesFromMpesa($mpesa);
            if ($codes === []) {
                continue;
            }

            $primary = $codes[0];
            if (DB::table('mpesa_paybill_accounts')->where('primary_short_code', $primary)->exists()) {
                continue;
            }

            $accountId = DB::table('mpesa_paybill_accounts')->insertGetId([
                'organization_id' => (int) $org->id,
                'name' => 'Default paybill',
                'primary_short_code' => $primary,
                'shortcode' => trim((string) ($mpesa['shortcode'] ?? '')) ?: null,
                'till_number' => trim((string) ($mpesa['till_number'] ?? '')) ?: null,
                'child_storecode' => trim((string) ($mpesa['child_storecode'] ?? '')) ?: null,
                'branch_id' => null,
                'route_id' => null,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'mpesa_paybill_account_id')) {
                $branches = DB::table('branches')
                    ->where('organization_id', (int) $org->id)
                    ->select('id', 'settings', 'branch_name')
                    ->get();
                foreach ($branches as $branch) {
                    $branchSettings = is_string($branch->settings)
                        ? json_decode($branch->settings, true)
                        : (is_array($branch->settings) ? $branch->settings : []);
                    $branchMpesa = is_array($branchSettings['mpesa'] ?? null) ? $branchSettings['mpesa'] : [];
                    $branchCodes = $this->codesFromMpesa($branchMpesa);
                    if ($branchCodes === []) {
                        continue;
                    }
                    $branchPrimary = $branchCodes[0];
                    if ($branchPrimary === $primary) {
                        DB::table('branches')->where('id', $branch->id)->update([
                            'mpesa_paybill_account_id' => $accountId,
                        ]);
                        continue;
                    }
                    if (DB::table('mpesa_paybill_accounts')->where('primary_short_code', $branchPrimary)->exists()) {
                        $existingId = (int) DB::table('mpesa_paybill_accounts')
                            ->where('primary_short_code', $branchPrimary)
                            ->value('id');
                        DB::table('branches')->where('id', $branch->id)->update([
                            'mpesa_paybill_account_id' => $existingId,
                        ]);
                        continue;
                    }

                    $branchAccountId = DB::table('mpesa_paybill_accounts')->insertGetId([
                        'organization_id' => (int) $org->id,
                        'name' => trim((string) ($branch->branch_name ?? 'Branch')).' paybill',
                        'primary_short_code' => $branchPrimary,
                        'shortcode' => trim((string) ($branchMpesa['shortcode'] ?? '')) ?: null,
                        'till_number' => trim((string) ($branchMpesa['till_number'] ?? '')) ?: null,
                        'child_storecode' => trim((string) ($branchMpesa['child_storecode'] ?? '')) ?: null,
                        'branch_id' => (int) $branch->id,
                        'route_id' => null,
                        'is_default' => false,
                        'is_active' => true,
                        'sort_order' => 10,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('branches')->where('id', $branch->id)->update([
                        'mpesa_paybill_account_id' => $branchAccountId,
                    ]);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $mpesa
     *  @return list<string>
     */
    protected function codesFromMpesa(array $mpesa): array
    {
        $codes = [];
        foreach (['child_storecode', 'till_number', 'shortcode'] as $key) {
            $value = trim((string) ($mpesa[$key] ?? ''));
            if ($value !== '' && ! in_array($value, $codes, true)) {
                $codes[] = $value;
            }
        }

        return $codes;
    }
};
