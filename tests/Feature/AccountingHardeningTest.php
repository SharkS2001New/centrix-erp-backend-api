<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AccountingHardeningTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_patch_journal_status_is_blocked(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $cash = ChartOfAccount::query()
            ->where('organization_id', $admin->organization_id)
            ->where('account_code', '1000')
            ->firstOrFail();
        $sales = ChartOfAccount::query()
            ->where('organization_id', $admin->organization_id)
            ->where('account_code', '4000')
            ->firstOrFail();

        $created = $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_number' => 'JE-HARD-001',
            'entry_date' => '2026-06-15',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100],
            ],
        ])->assertCreated();

        $entryId = $created->json('id');

        $this->patchJson("/api/v1/journal-entries/{$entryId}", [
            'status' => 'posted',
            'posted_at' => now()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['journal_entry']);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entryId,
            'status' => 'draft',
        ]);
    }

    public function test_cashier_without_accounting_permission_cannot_list_chart_of_accounts(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $cashierRole = Role::where('role_name', 'Cashier')->firstOrFail();
        $cashier = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $cashierRole->id,
            'username' => 'cashier_no_acct_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Cashier No Accounting',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/chart-of-accounts')
            ->assertStatus(403);
    }

    public function test_journal_line_direct_create_is_blocked(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/journal-entry-lines', [
            'journal_entry_id' => 1,
            'account_id' => 1,
            'debit' => 10,
            'credit' => 0,
        ])->assertStatus(405);
    }
}
