<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PaymentMethodAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_mobile_driver_can_list_payment_methods_for_delivery_collection(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $driverRole = Role::where('role_name', 'Driver')->firstOrFail();
        PaymentMethod::create([
            'organization_id' => $admin->organization_id,
            'method_name' => 'Driver Cash',
            'method_code' => 'DRIVER_CASH',
            'requires_reference' => false,
            'is_active' => true,
        ]);

        $driver = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $driverRole->id,
            'username' => 'driver_payment_methods_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Driver Payment Methods',
            'access_scope' => 'branch',
            'is_mobile_user' => true,
            'login_channels' => ['mobile'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/v1/payment-methods?per_page=50&filter%5Bis_active%5D=1')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('method_code');
        $this->assertTrue($codes->contains('DRIVER_CASH'));
    }

    public function test_mobile_driver_cannot_manage_payment_methods(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $driverRole = Role::where('role_name', 'Driver')->firstOrFail();
        $driver = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $driverRole->id,
            'username' => 'driver_no_payment_manage_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Driver No Payment Manage',
            'access_scope' => 'branch',
            'is_mobile_user' => true,
            'login_channels' => ['mobile'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/payment-methods', [
            'method_name' => 'Driver Created Method',
            'method_code' => 'DRIVER_CREATED',
            'requires_reference' => false,
            'is_active' => true,
        ])->assertForbidden();
    }
}
