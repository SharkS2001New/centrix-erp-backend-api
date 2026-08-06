<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraReceiptsReportTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function seedLicense(User $user): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_kra_receipts_report_returns_per_receipt_rows(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);

        $sale = Sale::create([
            'order_num' => 90001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1500,
            'total_vat' => 0,
        ]);

        DB::table('kra_responses')->insert([
            'sale_id' => $sale->id,
            'organization_id' => $org->id,
            'order_no' => 90001,
            'invoice_number' => 'CU-TEST-90001',
            'serial_number' => 'SCU-TEST-1',
            'signature_link' => 'https://etims.example/verify/90001',
            'receipt_signature' => 'sig-test',
            'kra_timestamp' => now()->toIso8601String(),
            'status' => 'success',
            'request_payload' => json_encode([
                'sn' => 'SCU-TEST-1',
                'plu_data' => [
                    ['item_Name' => 'Test item', 'SaleQty' => '1', 'SalePrice' => '1500.00'],
                ],
            ]),
            'response_payload' => json_encode([
                'scu_id' => 'KRACU0300000001',
                'cu_inv_no' => '0000001',
                'invoice_number' => 'CU-TEST-90001',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->getJson('/api/v1/reports/kra-receipts?from_date='.now()->toDateString().'&to_date='.now()->toDateString().'&date_column=receipt_date')
            ->assertOk();

        $rows = collect($res->json('data') ?? []);
        $hit = $rows->firstWhere('invoice_number', 'CU-TEST-90001');
        $this->assertNotNull($hit, 'Expected per-receipt KRA row with CU number');
        $this->assertSame(90001, (int) ($hit['order_no'] ?? 0));
        $this->assertSame('CU-TEST-90001', $hit['invoice_number'] ?? null);
        $this->assertSame('SCU-TEST-1', $hit['serial_number'] ?? null);
        $this->assertArrayHasKey('receipt_date', $hit);
        $this->assertArrayHasKey('signature_link', $hit);
        $this->assertArrayHasKey('request_payload', $hit);
        $this->assertArrayHasKey('response_payload', $hit);
    }

    public function test_kra_compliance_summary_and_unfiscalized_sales(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);

        $okSale = Sale::create([
            'order_num' => 90011,
            'branch_id' => $admin->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 1160,
            'total_vat' => 160,
            'completed_at' => now(),
        ]);

        $gapSale = Sale::create([
            'order_num' => 90012,
            'branch_id' => $admin->branch_id,
            'organization_id' => $org->id,
            'channel' => 'pos',
            'cashier_id' => $admin->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'order_total' => 580,
            'total_vat' => 80,
            'completed_at' => now(),
        ]);

        DB::table('kra_responses')->insert([
            'sale_id' => $okSale->id,
            'organization_id' => $org->id,
            'order_no' => 90011,
            'invoice_number' => 'CU-TEST-90011',
            'serial_number' => 'SCU-TEST-2',
            'status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kra_responses')->insert([
            'sale_id' => $gapSale->id,
            'organization_id' => $org->id,
            'order_no' => 90012,
            'invoice_number' => null,
            'status' => 'failed',
            'error_message' => 'Device offline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $summary = $this->getJson('/api/v1/reports/kra-compliance-summary?from_date='.now()->toDateString().'&to_date='.now()->toDateString().'&date_column=receipt_date')
            ->assertOk();

        $summaryRows = collect($summary->json('data') ?? []);
        $this->assertTrue($summaryRows->isNotEmpty(), 'Expected compliance summary rows');
        $first = $summaryRows->first();
        $this->assertArrayHasKey('success_rate_pct', $first);
        $this->assertArrayHasKey('fiscalized_vat', $first);
        $this->assertArrayHasKey('cu_invoice_count', $first);

        $gaps = $this->getJson('/api/v1/reports/kra-unfiscalized-sales?from_date='.now()->toDateString().'&to_date='.now()->toDateString().'&date_column=sale_date')
            ->assertOk();

        $gapRows = collect($gaps->json('data') ?? []);
        $hit = $gapRows->firstWhere('order_no', 90012);
        $this->assertNotNull($hit, 'Expected unfiscalized sale without successful CU');
        $this->assertSame('failed', $hit['last_kra_status'] ?? null);
    }
}
