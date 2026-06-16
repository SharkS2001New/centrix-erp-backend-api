<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_admin_can_list_audit_logs_with_filters(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->assertGreaterThan(0, AuditLog::count());

        $this->getJson('/api/v1/audit-logs?per_page=10&from_date=2020-01-01')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }

    public function test_creating_category_writes_audit_log(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $before = AuditLog::count();

        $this->postJson('/api/v1/categories', [
            'category_name' => 'Audit Test Category',
        ])->assertCreated();

        $this->assertSame($before + 1, AuditLog::count());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'create',
            'table_name' => 'categories',
        ]);
    }

    public function test_audit_logs_cannot_be_created_or_deleted_via_api(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/audit-logs', [
            'user_id' => $admin->id,
            'action' => 'create',
            'table_name' => 'products',
            'record_id' => '1',
        ])->assertForbidden();

        $logId = AuditLog::value('id');
        $this->deleteJson("/api/v1/audit-logs/{$logId}")->assertForbidden();
    }

    public function test_cashier_cannot_list_audit_logs(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }
}
