<?php

namespace Tests\Feature;

use App\Models\CurrentStock;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockTakeLine;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Inventory\StockTakeApprovalService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class InventoryApprovalWorkflowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Inventory Approval Test '.md5(json_encode($codes))],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', $codes)
            ->pluck('id')
            ->all();

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => (int) $permissionId,
            ]);
        }

        return User::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'inv_approval_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Inventory Approval Test',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    protected function enableDamageApproval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['inventory'] = array_merge($settings['inventory'] ?? [], [
            'damage_write_off_approval_enabled' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function seedShopStock(User $user, string $productCode, float $qty = 20): void
    {
        CurrentStock::query()->updateOrCreate(
            ['product_code' => $productCode, 'branch_id' => $user->branch_id],
            ['shop_quantity' => $qty, 'store_quantity' => 10],
        );
    }

    public function test_manager_can_complete_stock_take_from_action_request(): void
    {
        $clerk = $this->userWithPermissions(['inventory.stock_take.create']);
        $manager = $this->userWithPermissions(['inventory.stock_take.approve']);
        $product = Product::query()->firstOrFail();

        $session = StockTakeSession::create([
            'branch_id' => $clerk->branch_id,
            'session_code' => 'ST-APPR-'.uniqid(),
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $clerk->id,
        ]);

        CurrentStock::query()->updateOrCreate(
            ['product_code' => $product->product_code, 'branch_id' => $clerk->branch_id],
            ['shop_quantity' => 10, 'store_quantity' => 0],
        );

        StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'system_quantity' => 10,
            'counted_quantity' => 9,
        ]);

        $actionRequest = app(StockTakeApprovalService::class)->requestCompletion($clerk, $session);

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/action-requests/{$actionRequest->id}/approve")
            ->assertOk();

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_manager_can_apply_damage_write_off_from_action_request(): void
    {
        $this->enableDamageApproval();
        $clerk = $this->userWithPermissions(['inventory.damages.create']);
        $manager = User::where('username', 'admin')->firstOrFail();
        $product = Product::query()->firstOrFail();
        $this->seedShopStock($clerk, $product->product_code);

        $actionRequest = app(ActionRequestService::class)->requestApproval($clerk, [
            'type' => 'damage_write_off',
            'module' => 'inventory',
            'reference_type' => 'damage',
            'reference_id' => 0,
            'approver_permission' => 'inventory.manage',
            'title' => 'Damage write-off approval required',
            'message' => 'Clerk requested damage write-off.',
            'reason' => 'Broken seal',
            'severity' => 'danger',
            'action_url' => '/inventory/damages',
            'allow_duplicate_reference' => true,
            'payload' => [
                'action' => 'create',
                'data' => [
                    'branch_id' => $clerk->branch_id,
                    'product_code' => $product->product_code,
                    'stock_location' => 'shop',
                    'quantity' => 1,
                    'reason' => 'Broken seal',
                ],
                'action_url' => '/inventory/damages',
            ],
        ]);

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/action-requests/{$actionRequest->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('damages', [
            'product_code' => $product->product_code,
            'branch_id' => $clerk->branch_id,
        ]);
    }
}
