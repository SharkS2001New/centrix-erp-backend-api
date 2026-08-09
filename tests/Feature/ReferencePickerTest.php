<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ReferencePickerTest extends TestCase
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

    public function test_reference_users_and_vats_do_not_require_admin_or_catalogue_view(): void
    {
        PermissionMatrixService::ensure();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->seedLicense($cashier);
        Sanctum::actingAs($cashier);

        // Admin user directory still requires admin module permission.
        $this->getJson('/api/v1/users')->assertForbidden();
        // POS cashiers may read /vats via checkout permissions; routes CRUD does not.
        $this->getJson('/api/v1/routes')->assertForbidden();

        $this->getJson('/api/v1/reference/users?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/vats?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/categories?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/uoms?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/suppliers?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/sub-categories?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/routes?per_page=5')->assertOk();
        $this->getJson('/api/v1/reference/payment-methods?per_page=5')->assertOk();
    }

    public function test_reference_users_sales_capable_filters_to_sellers(): void
    {
        PermissionMatrixService::ensure();
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($admin);
        Sanctum::actingAs($admin);

        $stockRole = Role::query()->firstOrCreate(
            ['role_name' => 'Stock Clerk Ref '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );
        $stockPermId = Permission::query()
            ->where('permission_code', 'inventory.stock.view')
            ->value('id');
        $this->assertNotNull($stockPermId);
        DB::table('role_permissions')->where('role_id', $stockRole->id)->delete();
        DB::table('role_permissions')->insert([
            'role_id' => $stockRole->id,
            'permission_id' => $stockPermId,
        ]);

        $stockUser = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $stockRole->id,
            'username' => 'stock_ref_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Stock Ref User',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        $mobileRole = Role::query()->firstOrCreate(
            ['role_name' => 'Mobile Seller Ref '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );
        $mobilePermId = Permission::query()
            ->where('permission_code', 'mobile_sales.orders.create')
            ->value('id');
        $this->assertNotNull($mobilePermId);
        DB::table('role_permissions')->where('role_id', $mobileRole->id)->delete();
        DB::table('role_permissions')->insert([
            'role_id' => $mobileRole->id,
            'permission_id' => $mobilePermId,
        ]);

        $mobileUser = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $mobileRole->id,
            'username' => 'mobile_ref_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Mobile Ref User',
            'access_scope' => 'branch',
            'login_channels' => ['mobile'],
            'is_active' => true,
        ]);

        $all = $this->getJson('/api/v1/reference/users?per_page=200')
            ->assertOk();
        $allIds = collect($all->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertTrue($allIds->contains((int) $stockUser->id));

        $sellers = $this->getJson('/api/v1/reference/users?sales_capable=1&per_page=200')
            ->assertOk();
        $sellerIds = collect($sellers->json('data'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertFalse($sellerIds->contains((int) $stockUser->id));
        $this->assertTrue($sellerIds->contains((int) $mobileUser->id));
        $this->assertTrue($sellerIds->contains((int) $admin->id));

        $cashier = User::where('username', 'cashier')->first();
        if ($cashier) {
            $this->assertTrue($sellerIds->contains((int) $cashier->id));
        }
    }

    public function test_reference_routes_search_by_name_and_default_active(): void
    {
        PermissionMatrixService::ensure();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->seedLicense($cashier);
        Sanctum::actingAs($cashier);

        $response = $this->getJson('/api/v1/reference/routes?per_page=50')
            ->assertOk();

        $names = collect($response->json('data'))
            ->map(fn (array $row) => (string) ($row['route_name'] ?? ''))
            ->filter()
            ->values()
            ->all();
        $this->assertNotEmpty($names);

        $sorted = $names;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($sorted, $names, 'Routes should be sorted by name');

        $needle = $names[0];
        $search = $this->getJson('/api/v1/reference/routes?q='.urlencode(substr($needle, 0, 3)).'&per_page=50')
            ->assertOk();
        $matched = collect($search->json('data'))->pluck('route_name')->all();
        $this->assertNotEmpty($matched);
    }

    public function test_reference_payment_methods_list_active_sorted(): void
    {
        PermissionMatrixService::ensure();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->seedLicense($cashier);
        Sanctum::actingAs($cashier);

        $response = $this->getJson('/api/v1/reference/payment-methods?per_page=50')
            ->assertOk();

        $labels = collect($response->json('data'))
            ->map(fn (array $row) => (string) ($row['method_name'] ?? $row['method_code'] ?? ''))
            ->filter()
            ->values()
            ->all();
        $this->assertNotEmpty($labels);

        $sorted = $labels;
        sort($sorted, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($sorted, $labels, 'Payment methods should be sorted by name');
    }

    public function test_kra_invoices_permission_is_registered(): void
    {
        PermissionMatrixService::ensure();
        $codes = PermissionMatrixService::allRegistryCodes();

        $this->assertContains('pricing_tax.kra_invoices.view', $codes);
    }
}
