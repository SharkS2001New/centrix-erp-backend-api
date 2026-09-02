<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\LpoWorkflowService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LpoAdminForceMarkSentTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function ensureLpoStatuses(): void
    {
        foreach ([
            0 => 'Awaiting check',
            1 => 'Awaiting approval',
            2 => 'Awaiting send',
            3 => 'Awaiting receive',
            4 => 'Partially received',
            5 => 'Fully received',
        ] as $code => $name) {
            DB::table('lpo_statuses')->updateOrInsert(
                ['status_code' => $code],
                ['status_name' => $name],
            );
        }
    }

    public function test_admin_can_force_mark_lpo_as_sent_from_awaiting_check(): void
    {
        $this->ensureLpoStatuses();
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $create = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'ordered_qty' => 1,
                    'cost_price' => 50,
                ],
            ],
        ])->assertCreated();

        $lpoNo = (int) $create->json('lpo_no');

        $this->postJson("/api/v1/lpo-mst/{$lpoNo}/workflow", [
            'action' => 'force_mark_sent',
        ])->assertOk()
            ->assertJsonPath('lpo.lpo_status_code', LpoWorkflowService::STATUS_AWAITING_RECEIVE);

        $this->assertDatabaseHas('lpo_mst', [
            'lpo_no' => $lpoNo,
            'lpo_status_code' => LpoWorkflowService::STATUS_AWAITING_RECEIVE,
        ]);
    }

    public function test_non_admin_cannot_force_mark_lpo_as_sent(): void
    {
        $this->ensureLpoStatuses();
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $create = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'ordered_qty' => 1,
                    'cost_price' => 50,
                ],
            ],
        ])->assertCreated();

        $lpoNo = (int) $create->json('lpo_no');

        $userId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'lpo_force_sent_denied',
            'email' => 'lpo_force_sent_denied@example.test',
            'password' => $admin->password,
            'full_name' => 'LPO Force Sent Denied',
            'is_admin' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::query()->findOrFail($userId);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/lpo-mst/{$lpoNo}/workflow", [
            'action' => 'force_mark_sent',
        ])->assertStatus(422);
    }
}
