<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\ModuleRegistry;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BackofficeSalesCartTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_backoffice_cart_uses_backend_channel_when_external_pos_disabled(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.pos' => false,
            'sales.backend' => true,
            'inventory' => true,
            'customers_suppliers' => true,
        ]);
        $org->save();

        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'order_source' => 'backoffice',
            'branch_id' => $user->branch_id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('channel', 'backend')
            ->assertJsonPath('order_source', 'backoffice');
    }
}
