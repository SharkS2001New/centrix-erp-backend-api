<?php

namespace Tests\Feature;

use App\Models\ActionRequest;
use App\Models\InAppNotification;
use App\Models\LpoMst;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\LpoWorkflowService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LpoApprovalRequestTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_purchaser_can_submit_lpo_for_manager_approval(): void
    {
        $org = Organization::query()->where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['procurement'] = array_merge($settings['procurement'] ?? [], [
            'require_lpo_approval' => true,
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $purchaser = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($purchaser);

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
            'action' => 'submit_for_approval',
        ])->assertOk()
            ->assertJsonPath('lpo_status_code', LpoWorkflowService::STATUS_AWAITING_APPROVAL)
            ->assertJsonPath('approval_pending', true);

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $org->id,
            'type' => 'lpo_approval',
            'reference_type' => 'lpo_mst',
            'reference_id' => $lpoNo,
            'status' => 'pending',
            'requested_by' => $purchaser->id,
        ]);

        $this->assertTrue(
            InAppNotification::query()
                ->where('organization_id', $org->id)
                ->where('type', 'approval')
                ->whereHas('actionRequest', fn ($q) => $q
                    ->where('reference_id', $lpoNo)
                    ->where('type', 'lpo_approval'))
                ->exists()
        );

        $actionUrl = "/lpo/{$lpoNo}";
        $this->assertDatabaseHas('action_requests', [
            'reference_id' => $lpoNo,
            'type' => 'lpo_approval',
        ]);
        $request = ActionRequest::query()
            ->where('reference_id', $lpoNo)
            ->where('type', 'lpo_approval')
            ->firstOrFail();
        $payload = is_array($request->payload) ? $request->payload : [];
        $this->assertSame($actionUrl, $payload['action_url'] ?? null);
    }

    public function test_approving_lpo_notifies_requester(): void
    {
        $org = Organization::query()->where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['procurement'] = array_merge($settings['procurement'] ?? [], [
            'require_lpo_approval' => true,
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $purchaser = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($purchaser);

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
            'action' => 'submit_for_approval',
        ])->assertOk();

        $requestId = (int) ActionRequest::query()
            ->where('reference_id', $lpoNo)
            ->where('type', 'lpo_approval')
            ->value('id');

        $approverId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'organization_id' => $org->id,
            'branch_id' => $purchaser->branch_id,
            'role_id' => $purchaser->role_id,
            'username' => 'lpo_approver_test',
            'email' => 'lpo_approver_test@example.test',
            'password' => $purchaser->password,
            'full_name' => 'LPO Approver Test',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$requestId}/approve")
            ->assertOk();

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $purchaser->id,
            'type' => 'approval_outcome',
            'is_read' => false,
        ]);
    }

    public function test_rejecting_lpo_notifies_requester_and_returns_to_check(): void
    {
        $org = Organization::query()->where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['procurement'] = array_merge($settings['procurement'] ?? [], [
            'require_lpo_approval' => true,
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        $purchaser = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($purchaser);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $product = Product::firstOrFail();

        $create = $this->postJson('/api/v1/lpo-mst/full', [
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'ordered_qty' => 2,
                    'cost_price' => 75,
                ],
            ],
        ])->assertCreated();

        $lpoNo = (int) $create->json('lpo_no');

        $this->postJson("/api/v1/lpo-mst/{$lpoNo}/workflow", [
            'action' => 'submit_for_approval',
        ])->assertOk();

        $requestId = (int) ActionRequest::query()
            ->where('reference_id', $lpoNo)
            ->where('type', 'lpo_approval')
            ->value('id');

        $approverId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'organization_id' => $org->id,
            'branch_id' => $purchaser->branch_id,
            'role_id' => $purchaser->role_id,
            'username' => 'lpo_rejector_test',
            'email' => 'lpo_rejector_test@example.test',
            'password' => $purchaser->password,
            'full_name' => 'LPO Rejector Test',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$requestId}/reject", [
            'reason' => 'Pricing needs revision',
        ])->assertOk();

        $this->assertSame(
            LpoWorkflowService::STATUS_AWAITING_CHECK,
            (int) LpoMst::query()->where('lpo_no', $lpoNo)->value('lpo_status_code')
        );

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $purchaser->id,
            'type' => 'approval_outcome',
            'is_read' => false,
        ]);
    }
}
