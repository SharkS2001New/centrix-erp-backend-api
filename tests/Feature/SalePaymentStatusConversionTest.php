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
