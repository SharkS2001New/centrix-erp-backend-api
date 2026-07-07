<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class JournalEntryApprovalTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableJournalApproval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['accounting'] = array_merge($settings['accounting'] ?? [], [
            'journal_entry_approval_enabled' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    protected function userWithPermissions(array $codes): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Journal Approval Test '.md5(json_encode($codes))],
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
            'username' => 'journal_approval_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Journal Approval Test',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    protected function createDraftEntry(User $user): JournalEntry
    {
        $cash = ChartOfAccount::query()
            ->where('organization_id', $user->organization_id)
            ->where('account_code', '1000')
            ->firstOrFail();
        $sales = ChartOfAccount::query()
            ->where('organization_id', $user->organization_id)
            ->where('account_code', '4000')
            ->firstOrFail();

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_number' => 'JE-APPR-'.uniqid(),
            'entry_date' => '2026-06-15',
            'description' => 'Pending approval',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 500, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 500],
            ],
        ])->assertCreated();

        return JournalEntry::query()->findOrFail((int) $created->json('id'));
    }

    public function test_approver_can_post_journal_entry_from_action_request(): void
    {
        $this->enableJournalApproval();
        $bookkeeper = $this->userWithPermissions(['accounting.journal_entries.create']);
        $approver = $this->userWithPermissions(['accounting.journal_entries.approve']);
        $entry = $this->createDraftEntry($bookkeeper);

        $actionRequest = app(ActionRequestService::class)->requestApproval($bookkeeper, [
            'type' => 'journal_entry',
            'module' => 'accounting',
            'reference_type' => 'journal_entry',
            'reference_id' => (int) $entry->id,
            'approver_permission' => 'accounting.journal_entries.approve',
            'title' => 'Journal entry posting approval',
            'message' => "Posting approval for {$entry->entry_number}.",
            'reason' => $entry->description,
            'severity' => 'warning',
            'action_url' => "/accounting/journal-entries/{$entry->id}",
            'payload' => [
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date,
                'action_url' => "/accounting/journal-entries/{$entry->id}",
            ],
        ]);

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$actionRequest->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'status' => 'posted',
        ]);

        $this->assertDatabaseHas('action_requests', [
            'id' => $actionRequest->id,
            'status' => 'approved',
        ]);
    }

    public function test_bookkeeper_must_request_post_when_approval_enabled(): void
    {
        $this->enableJournalApproval();
        $bookkeeper = $this->userWithPermissions(['accounting.journal_entries.create']);
        $entry = $this->createDraftEntry($bookkeeper);

        Sanctum::actingAs($bookkeeper);

        $this->assertFalse(app(\App\Services\Accounting\JournalEntryApprovalService::class)->canDirectPost($bookkeeper));

        $this->postJson("/api/v1/accounting/journal-entries/{$entry->id}/request-post")
            ->assertStatus(202)
            ->assertJsonPath('pending_approval', true);

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $bookkeeper->organization_id,
            'type' => 'journal_entry',
            'status' => 'pending',
            'reference_id' => $entry->id,
            'requested_by' => $bookkeeper->id,
        ]);
    }
}
