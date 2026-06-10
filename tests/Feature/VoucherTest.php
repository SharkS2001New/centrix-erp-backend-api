<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Voucher;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class VoucherTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_voucher_crud_is_scoped_to_organization(): void
    {
        $created = $this->postJson('/api/v1/vouchers', [
            'voucher_code' => 'save10',
            'name' => 'Save 10%',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'min_order_amount' => 500,
            'max_redemptions' => 100,
            'is_active' => true,
        ])->assertCreated()->json();

        $this->assertEquals('SAVE10', $created['voucher_code']);
        $this->assertEquals($this->user->organization_id, $created['organization_id']);

        $this->getJson('/api/v1/vouchers?q=SAVE10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created['id']);

        $this->putJson("/api/v1/vouchers/{$created['id']}", [
            'name' => 'Save ten percent',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('name', 'Save ten percent');

        $this->deleteJson("/api/v1/vouchers/{$created['id']}")->assertNoContent();
        $this->assertDatabaseMissing('vouchers', ['id' => $created['id']]);
    }

    public function test_voucher_code_must_be_unique_per_organization(): void
    {
        Voucher::create([
            'organization_id' => $this->user->organization_id,
            'voucher_code' => 'WELCOME',
            'discount_type' => 'fixed',
            'discount_value' => 50,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/vouchers', [
            'voucher_code' => 'welcome',
            'discount_type' => 'fixed',
            'discount_value' => 25,
        ])->assertStatus(422);
    }
}
