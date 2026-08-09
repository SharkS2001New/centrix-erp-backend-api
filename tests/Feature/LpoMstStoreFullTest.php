<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\LpoMstController;
use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\LpoMst;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Kra\SalesVatCalculator;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LpoMstStoreFullTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_store_full_sets_organization_id_and_lpo_seq(): void
    {
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $response = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'ordered_qty' => 2,
                    'cost_price' => 100,
                    'uom' => 'kg',
                ],
            ],
        ]);

        $response->assertCreated();
        $lpoNo = (int) $response->json('lpo_no');
        $this->assertGreaterThan(0, $lpoNo);

        $lpo = LpoMst::query()->findOrFail($lpoNo);
        $this->assertSame((int) $admin->organization_id, (int) $lpo->organization_id);
        $this->assertGreaterThan(0, (int) $lpo->lpo_seq);
        $this->assertSame($supplier->id, (int) $lpo->supplier_id);
    }

    public function test_lpo_totals_treat_cost_as_vat_inclusive(): void
    {
        $product = Product::with('vat')->firstOrFail();
        $controller = app(LpoMstController::class);
        $method = new ReflectionMethod(LpoMstController::class, 'computeTotals');
        $method->setAccessible(true);

        $totals = $method->invoke($controller, [
            [
                'product_code' => $product->product_code,
                'ordered_qty' => 2,
                'cost_price' => 116,
            ],
        ]);

        $gross = 232.0;
        $rate = SalesVatCalculator::vatRateFromProduct($product);
        $expectedVat = SalesVatCalculator::vatFromInclusiveGross($gross, $rate);

        $this->assertEqualsWithDelta($gross, $totals['total'], 0.01);
        $this->assertEqualsWithDelta($expectedVat, $totals['vat'], 0.01);
        $this->assertEqualsWithDelta($gross - $expectedVat, $totals['subtotal'], 0.01);
        // Must not be exclusive+VAT (old bug: 232 * 1.16).
        if ($rate > 0) {
            $this->assertLessThan($gross * (1 + $rate / 100) - 0.5, $totals['total']);
        }
    }

    public function test_admin_can_delete_sent_or_received_lpo_status(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $service = app(\App\Services\LpoModuleService::class);

        $this->assertTrue($service->canDeleteLpo($admin, 0));
        $this->assertTrue($service->canDeleteLpo($admin, 3));
        $this->assertTrue($service->canDeleteLpo($admin, 5));
        $this->assertTrue($service->canDeleteLpo($admin, 6));
    }

    public function test_non_admin_cannot_delete_sent_or_received_lpo_status(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $staff = User::query()
            ->where('organization_id', $admin->organization_id)
            ->where('is_admin', 0)
            ->where('is_super_admin', 0)
            ->where('id', '!=', $admin->id)
            ->first();
        if (! $staff) {
            $this->markTestSkipped('No non-admin user in seed data.');
        }

        $service = app(\App\Services\LpoModuleService::class);
        $this->assertTrue($service->canDeleteLpo($staff, 0));
        $this->assertTrue($service->canDeleteLpo($staff, 2));
        $this->assertFalse($service->canDeleteLpo($staff, 3));
        $this->assertFalse($service->canDeleteLpo($staff, 5));
    }
}
