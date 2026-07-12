<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_admin_can_list_bank_accounts(): void
    {
        $this->getJson('/api/v1/accounting/bank-accounts')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'account_code', 'account_name']]]);
    }

    public function test_create_match_and_complete_bank_reconciliation(): void
    {
        $orgId = (int) $this->user->organization_id;
        $bank = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1100')
            ->firstOrFail();
        $sales = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '4000')
            ->firstOrFail();

        $entry = JournalEntry::create([
            'organization_id' => $orgId,
            'entry_number' => 'JE-BANK-RECON',
            'entry_date' => '2026-06-15',
            'description' => 'Customer deposit',
            'created_by' => $this->user->id,
        ]);
        $entry->forceFill([
            'status' => 'posted',
            'posted_at' => now(),
        ])->save();

        $bankLine = $entry->lines()->create([
            'account_id' => $bank->id,
            'debit' => 2500,
            'credit' => 0,
            'line_notes' => 'Deposit ref DEP-001',
        ]);
        $entry->lines()->create([
            'account_id' => $sales->id,
            'debit' => 0,
            'credit' => 2500,
        ]);

        $created = $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'title' => 'June bank recon',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'statement_balance' => 2500,
            'statement_lines' => [
                [
                    'line_date' => '2026-06-15',
                    'description' => 'Customer deposit',
                    'reference' => 'DEP-001',
                    'amount' => 2500,
                ],
            ],
        ])->assertCreated();

        $reconciliationId = (int) $created->json('id');
        $this->assertSame('in_progress', $created->json('status'));

        $detail = $this->getJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}")
            ->assertOk()
            ->assertJsonPath('reconciliation.id', $reconciliationId);

        $statementLineId = (int) $detail->json('statement_lines.0.id');
        $this->assertNotEmpty($detail->json('book_items'));
        $this->assertNotEmpty($detail->json('suggestions'));

        $this->postJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}/matches", [
            'bank_statement_line_id' => $statementLineId,
            'journal_entry_line_id' => $bankLine->id,
            'match_type' => 'manual',
        ])->assertOk()
            ->assertJsonPath('variance', 0);

        $this->postJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}/complete", [
            'notes' => 'Balanced',
        ])->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('bank_reconciliations', [
            'id' => $reconciliationId,
            'status' => 'completed',
        ]);
    }

    public function test_csv_import_parses_statement_lines(): void
    {
        $orgId = (int) $this->user->organization_id;
        $bank = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1100')
            ->firstOrFail();

        $csv = <<<'CSV'
date,description,reference,amount
2026-06-10,Wire transfer,WT-88,1200.50
2026-06-12,Bank charge,CHG-1,-150
CSV;

        $created = $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'period_end' => '2026-06-30',
            'statement_balance' => 1050.50,
            'csv' => $csv,
        ])->assertCreated();

        $reconciliationId = (int) $created->json('id');

        $this->getJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}")
            ->assertOk()
            ->assertJsonCount(2, 'statement_lines')
            ->assertJsonPath('statement_lines.0.amount', 1200.5)
            ->assertJsonPath('statement_lines.1.amount', -150);
    }

    public function test_csv_import_supports_spaced_bank_headers(): void
    {
        $orgId = (int) $this->user->organization_id;
        $bank = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1100')
            ->firstOrFail();

        $csv = <<<'CSV'
Transaction Date,Narrative,Reference,Debit,Credit
10/06/2026,Customer deposit,DEP-77,2500.00,
11/06/2026,Bank charge,CHG-2,,150.00
CSV;

        $created = $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'period_end' => '2026-06-30',
            'statement_balance' => 2350,
            'csv' => $csv,
        ])->assertCreated();

        $reconciliationId = (int) $created->json('id');

        $this->getJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}")
            ->assertOk()
            ->assertJsonCount(2, 'statement_lines')
            ->assertJsonPath('statement_lines.0.amount', 2500)
            ->assertJsonPath('statement_lines.1.amount', -150);
    }

    public function test_csv_import_rejects_unparseable_csv(): void
    {
        $orgId = (int) $this->user->organization_id;
        $bank = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1100')
            ->firstOrFail();

        $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'period_end' => '2026-06-30',
            'statement_balance' => 100,
            'csv' => "foo,bar,baz\n1,2,3",
        ])->assertStatus(422);
    }

    public function test_can_import_statement_lines_into_existing_reconciliation(): void
    {
        $orgId = (int) $this->user->organization_id;
        $bank = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1100')
            ->firstOrFail();

        $created = $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'period_end' => '2026-06-30',
            'statement_balance' => 500,
        ])->assertCreated();

        $reconciliationId = (int) $created->json('id');

        $this->getJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}")
            ->assertOk()
            ->assertJsonCount(0, 'statement_lines');

        $csv = <<<'CSV'
date,description,reference,amount
2026-06-15,Customer deposit,DEP-99,500
CSV;

        $this->postJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}/statement-lines", [
            'csv' => $csv,
        ])->assertOk()
            ->assertJsonCount(1, 'statement_lines')
            ->assertJsonPath('statement_lines.0.reference', 'DEP-99');
    }
}
