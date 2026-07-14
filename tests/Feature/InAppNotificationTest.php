<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class InAppNotificationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_supplier_return_creates_approval_notification_for_other_approvers(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $approverId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'purchasing_mgr_test',
            'email' => 'purchasing_mgr_test@example.test',
            'password' => $admin->password,
            'full_name' => 'Purchasing Manager Test',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $productCode = DB::table('products')->value('product_code');

        $this->postJson('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'manual',
            'reason_scope' => 'order',
            'return_reason' => 'Damaged packaging on delivery',
            'lines' => [
                [
                    'product_code' => $productCode,
                    'quantity' => 2,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $admin->organization_id,
            'type' => 'supplier_return',
            'status' => 'pending',
            'requested_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('in_app_notifications', [
            'organization_id' => $admin->organization_id,
            'user_id' => $approver->id,
            'type' => 'approval',
        ]);

        Sanctum::actingAs($approver);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonStructure(['count', 'latest_id', 'pending_approvals_count']);

        $list = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($list);
        $this->assertSame('approval', $list[0]['type']);
        $this->assertTrue($list[0]['action_request']['can_approve']);
    }

    public function test_supplier_return_notification_includes_reason_and_proof(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $approverId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'purchasing_mgr_proof',
            'email' => 'purchasing_mgr_proof@example.test',
            'password' => $admin->password,
            'full_name' => 'Purchasing Manager Proof',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $productCode = DB::table('products')->value('product_code');

        $this->post('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'manual',
            'reason_scope' => 'order',
            'return_reason' => 'Damaged packaging on delivery',
            'lines' => json_encode([
                [
                    'product_code' => $productCode,
                    'quantity' => 2,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ]),
            'proof' => \Illuminate\Http\UploadedFile::fake()->create('damage.jpg', 100, 'image/jpeg'),
        ])->assertCreated();

        Sanctum::actingAs($approver);

        $list = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($list);
        $notification = $list[0];
        $this->assertStringContainsString('Reason: Damaged packaging on delivery', $notification['message']);
        $this->assertSame('Damaged packaging on delivery', $notification['action_request']['reason']);
        $this->assertSame('damage.jpg', $notification['action_request']['payload']['proof']['file_name'] ?? null);
    }

    public function test_customer_return_requires_reason(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = DB::table('products')->value('product_code');
        $sale = DB::table('sales')->value('id');

        $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale,
            'return_date' => '2026-06-20',
            'refund_method' => 'CASH',
            'lines' => [
                [
                    'product_code' => $product,
                    'quantity_sold' => 5,
                    'return_qty' => 1,
                    'unit_price' => 100,
                    'amount' => 100,
                ],
            ],
        ])->assertUnprocessable();
    }

    public function test_approver_can_resolve_action_request_from_notification_api(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $approverId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'purchasing_mgr_test2',
            'email' => 'purchasing_mgr_test2@example.test',
            'password' => $admin->password,
            'full_name' => 'Purchasing Manager Test 2',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $productCode = DB::table('products')->value('product_code');

        DB::table('current_stock')->updateOrInsert(
            ['product_code' => $productCode, 'branch_id' => $admin->branch_id],
            ['shop_quantity' => 0, 'store_quantity' => 20],
        );

        $docId = $this->postJson('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'manual',
            'reason_scope' => 'order',
            'return_reason' => 'Supplier sent wrong batch',
            'lines' => [
                [
                    'product_code' => $productCode,
                    'quantity' => 2,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ],
        ])->assertCreated()->json('data.id');

        $actionRequestId = (int) DB::table('action_requests')
            ->where('reference_id', $docId)
            ->value('id');

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$actionRequestId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('supplier_return_documents', [
            'id' => $docId,
            'status' => 'approved',
        ]);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 0);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_read_notification_is_hidden_from_bell_list(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $approverId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'purchasing_mgr_read_test',
            'email' => 'purchasing_mgr_read_test@example.test',
            'password' => $admin->password,
            'full_name' => 'Purchasing Manager Read Test',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $productCode = DB::table('products')->value('product_code');

        $this->postJson('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'manual',
            'reason_scope' => 'order',
            'return_reason' => 'Damaged packaging on delivery',
            'lines' => [
                [
                    'product_code' => $productCode,
                    'quantity' => 2,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ],
        ])->assertCreated();

        Sanctum::actingAs($approver);

        $notificationId = (int) DB::table('in_app_notifications')
            ->where('user_id', $approver->id)
            ->where('type', 'approval')
            ->value('id');

        $this->postJson("/api/v1/notifications/{$notificationId}/read")
            ->assertOk();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/notifications/all?bucket=pending_approvals')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_resolved_approval_notification_is_removed_from_all_buckets(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $approverId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'purchasing_mgr_bucket_test',
            'email' => 'purchasing_mgr_bucket_test@example.test',
            'password' => $admin->password,
            'full_name' => 'Purchasing Manager Bucket Test',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $productCode = DB::table('products')->value('product_code');

        DB::table('current_stock')->updateOrInsert(
            ['product_code' => $productCode, 'branch_id' => $admin->branch_id],
            ['shop_quantity' => 0, 'store_quantity' => 20],
        );

        $docId = $this->postJson('/api/v1/supplier-return-documents', [
            'supplier_id' => $supplier->id,
            'branch_id' => $admin->branch_id,
            'source_type' => 'manual',
            'reason_scope' => 'order',
            'return_reason' => 'Supplier sent wrong batch',
            'lines' => [
                [
                    'product_code' => $productCode,
                    'quantity' => 2,
                    'package_type' => 'pieces',
                    'stock_location' => 'store',
                ],
            ],
        ])->assertCreated()->json('data.id');

        $actionRequestId = (int) DB::table('action_requests')
            ->where('reference_id', $docId)
            ->value('id');

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$actionRequestId}/approve")
            ->assertOk();

        $this->getJson('/api/v1/notifications/all')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/notifications/all?bucket=pending_approvals')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_customer_return_creates_approval_notification_for_other_approvers(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $approverId = DB::table('users')->insertGetId([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'sales_mgr_test',
            'email' => 'sales_mgr_test@example.test',
            'password' => $admin->password,
            'full_name' => 'Sales Manager Test',
            'is_admin' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = User::query()->findOrFail($approverId);

        Sanctum::actingAs($admin);

        $product = DB::table('products')->value('product_code');
        $sale = DB::table('sales')->where('status', 'completed')->value('id')
            ?? DB::table('sales')->value('id');

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale,
            'return_date' => '2026-06-20',
            'refund_method' => 'CASH',
            'reason' => 'Damaged goods',
            'lines' => [
                [
                    'product_code' => $product,
                    'quantity_sold' => 5,
                    'return_qty' => 1,
                    'unit_price' => 100,
                    'amount' => 100,
                ],
            ],
        ])->assertCreated();

        $returnId = (int) $created->json('id');

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $admin->organization_id,
            'type' => 'customer_return',
            'reference_id' => $returnId,
            'status' => 'pending',
            'requested_by' => $admin->id,
        ]);

        $notification = DB::table('in_app_notifications')
            ->where('user_id', $approver->id)
            ->where('type', 'approval')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('/sales/returns?return_id='.$returnId, $notification->action_url);

        Sanctum::actingAs($approver);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }
}
