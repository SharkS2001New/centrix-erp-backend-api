<?php

namespace Tests\Feature;

use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\GetSupplierStatementTool;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiSupplierStatementToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_returns_balance_and_what_was_purchased(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        DB::table('lpo_statuses')->updateOrInsert(
            ['status_code' => 4],
            ['status_name' => 'Received'],
        );

        $lpo = LpoMst::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'supplier_id' => $supplier->id,
            'lpo_seq' => 99001,
            'reference_number' => 'PO-AI-SUP-001',
            'total_amount' => 2000,
            'net_amount' => 2000,
            'created_by' => $admin->id,
            'created_at' => '2026-08-15 09:00:00',
            'lpo_status_code' => 4,
        ]);

        LpoTxn::query()->create([
            'lpo_no' => $lpo->lpo_no,
            'product_code' => $product->product_code,
            'ordered_qty' => 20,
            'received_qty' => 20,
            'cost_price' => 100,
            'uom' => 'kg',
        ]);

        /** @var GetSupplierStatementTool $tool */
        $tool = app(GetSupplierStatementTool::class);
        $result = $tool->execute($admin, [
            'supplier_id' => (int) $supplier->id,
            'month' => 'august',
            'year' => 2026,
        ]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('2026-08-01', $result['period']['from_date'] ?? null);
        $this->assertSame('2026-08-31', $result['period']['to_date'] ?? null);
        $this->assertSame((int) $supplier->id, (int) ($result['supplier']['supplier_id'] ?? 0));
        $this->assertArrayHasKey('current_balance_due', $result['summary'] ?? []);
        $this->assertGreaterThanOrEqual(1, (int) ($result['summary']['lpos_in_period'] ?? 0));
        $this->assertTrue(
            collect($result['purchases'] ?? [])->contains(fn ($row) => (int) ($row['lpo_no'] ?? 0) === (int) $lpo->lpo_no),
        );
        $this->assertTrue(
            collect($result['line_items'] ?? [])->contains(
                fn ($line) => ($line['product_code'] ?? null) === $product->product_code
                    && (int) ($line['lpo_no'] ?? 0) === (int) $lpo->lpo_no,
            ),
        );
        $matched = collect($result['purchases_by_product'] ?? [])
            ->firstWhere('product_code', $product->product_code);
        $this->assertNotNull($matched);
        $this->assertArrayHasKey('qty_label', $matched);
    }

    public function test_registry_includes_supplier_statement_tool(): void
    {
        $names = array_map(fn ($tool) => $tool->name(), app(AiToolRegistry::class)->all());
        $this->assertContains('get_supplier_statement', $names);
    }
}
