<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SupplierStoreAutoCodeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_creating_supplier_without_code_auto_generates_supplier_code(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/suppliers', [
            'supplier_name' => 'Auto Code Supplies Ltd',
            'phone' => '0700000099',
        ]);

        $response->assertCreated();
        $code = (string) $response->json('supplier_code');
        $this->assertNotSame('', $code);
        $this->assertMatchesRegularExpression('/^SUP-\d+$/i', $code);
        $this->assertSame('Auto Code Supplies Ltd', $response->json('supplier_name'));

        $this->assertDatabaseHas('suppliers', [
            'id' => $response->json('id'),
            'organization_id' => $admin->organization_id,
            'supplier_code' => $code,
        ]);
    }

    public function test_creating_supplier_keeps_provided_supplier_code(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/suppliers', [
            'supplier_name' => 'Custom Code Co',
            'supplier_code' => 'SUP-CUSTOM-9',
        ]);

        $response->assertCreated()
            ->assertJsonPath('supplier_code', 'SUP-CUSTOM-9');
    }

    public function test_auto_generated_codes_increment_per_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $max = 0;
        foreach (Supplier::query()->where('organization_id', $admin->organization_id)->pluck('supplier_code') as $code) {
            if (preg_match('/^SUP-(\d+)$/i', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        $response = $this->postJson('/api/v1/suppliers', [
            'supplier_name' => 'Next Sequence Supplier',
        ]);

        $response->assertCreated();
        $expected = 'SUP-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
        $this->assertSame($expected, $response->json('supplier_code'));
    }
}
