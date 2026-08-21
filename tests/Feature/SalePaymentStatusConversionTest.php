<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SalePaymentStatusConversionTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected string $productCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->productCode = Product::first()->product_code;
        Sanctum::actingAs($this->user);

        $org = Organization::query()->findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];
        $sales['convert_to_paid_statuses'] = ['unpaid', 'pending_payment', 'mobile', 'whatsapp'];
        $sales['convert_to_unpaid_statuses'] = ['paid', 'pending_payment', 'mobile', 'whatsapp'];
        $settings['sales'] = $sales;
        $org->module_settings = $settings;
        $org->save();
    }

    public function test_convert_to_paid_and_back_to_unpaid(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
        ])->assertCreated()->json();

        $this->assertSame('unpaid', $sale['payment_status'] ?? null);

        $paid = $this->postJson("/api/v1/sales/{$sale['id']}/convert-to-paid")
            ->assertOk()
            ->json();

        $this->assertSame('paid', $paid['payment_status'] ?? null);
        $this->assertEqualsWithDelta(
            (float) ($paid['order_total'] ?? 0),
            (float) ($paid['amount_paid'] ?? 0),
            0.01,
        );

        $unpaid = $this->postJson("/api/v1/sales/{$sale['id']}/convert-to-unpaid")
            ->assertOk()
            ->json();

        $this->assertSame('unpaid', $unpaid['payment_status'] ?? null);
        $this->assertEqualsWithDelta(0.0, (float) ($unpaid['amount_paid'] ?? 0), 0.01);
    }

    public function test_convert_to_unpaid_marks_customer_sale_as_credit(): void
    {
        $customerNum = random_int(400000000, 499999999);
        \App\Models\Customer::query()->create([
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'customer_num' => $customerNum,
            'customer_name' => 'Convert Debtor '.$customerNum,
            'customer_type' => 'debtor',
            'created_by' => $this->user->id,
        ]);

        $sale = \App\Models\Sale::query()->create([
            'order_num' => $customerNum,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'cashier_id' => $this->user->id,
            'channel' => 'pos',
            'order_source' => 'pos',
            'customer_num' => $customerNum,
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'order_total' => 1800,
            'amount_paid' => 1800,
            'total_vat' => 0,
            'archived' => 0,
            'created_at' => now(),
        ]);

        $unpaid = $this->postJson("/api/v1/sales/{$sale->id}/convert-to-unpaid")
            ->assertOk()
            ->json();

        $this->assertSame('unpaid', $unpaid['payment_status'] ?? null);
        $this->assertEqualsWithDelta(0.0, (float) ($unpaid['amount_paid'] ?? 0), 0.01);

        $fresh = $sale->fresh();
        $this->assertTrue((bool) $fresh->is_credit_sale);
        $this->assertSame('CREDIT', strtoupper((string) $fresh->payment_method_code));

        $from = now()->subDays(14)->toDateString();
        $to = now()->toDateString();
        $ids = collect(
            $this->getJson(
                "/api/v1/sales?shop_debtors=1&filter[payment_status]=unpaid&from_date={$from}&to_date={$to}&per_page=200",
            )->assertOk()->json('data')
        )->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $sale->id, $ids);
    }

    public function test_convert_to_paid_rejected_when_stages_empty(): void
    {
        $org = Organization::query()->findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];
        $sales['convert_to_paid_statuses'] = [];
        $settings['sales'] = $sales;
        $org->module_settings = $settings;
        $org->save();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'backend',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'save_only' => true,
            'pay_now' => 0,
        ])->assertCreated()->json();

        $this->postJson("/api/v1/sales/{$sale['id']}/convert-to-paid")
            ->assertStatus(422);
    }
}
