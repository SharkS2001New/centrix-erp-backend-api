<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\BankStatementImportService;
use Illuminate\Http\UploadedFile;
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

    public function test_can_delete_in_progress_bank_reconciliation(): void
    {
        $orgId = (int) $this->user->organization_id;
        $bank = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1100')
            ->firstOrFail();

        $created = $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'period_end' => '2026-06-30',
            'statement_balance' => 1000,
        ])->assertCreated();

        $reconciliationId = (int) $created->json('id');

        $this->deleteJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('bank_reconciliations', ['id' => $reconciliationId]);
    }

    public function test_cannot_delete_completed_bank_reconciliation(): void
    {
        $orgId = (int) $this->user->organization_id;
        $bank = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1100')
            ->firstOrFail();

        $created = $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'period_end' => '2026-06-30',
            'statement_balance' => 0,
        ])->assertCreated();

        $reconciliationId = (int) $created->json('id');

        $this->postJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}/complete", [
            'notes' => 'Done',
        ])->assertOk();

        $this->deleteJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}")
            ->assertStatus(422);
    }

    public function test_statement_preview_parses_csv_and_ties_out(): void
    {
        $csv = <<<'CSV'
date,description,reference,amount
2026-06-10,Deposit,DEP-1,1000
2026-06-12,Fee,FEE-1,-50
CSV;

        $this->post('/api/v1/accounting/bank-statement-preview', [
            'csv' => $csv,
            'format' => 'csv',
        ])->assertOk()
            ->assertJsonPath('line_count', 2)
            ->assertJsonPath('activity_sum', 950)
            ->assertJsonPath('format', 'csv');
    }

    public function test_kenyan_style_pdf_text_extract_ties_out(): void
    {
        $text = <<<'TXT'
EQUITY BANK KENYA
Statement Period: 01/06/2026 to 30/06/2026
Opening Balance: 10,000.00
01/06/2026 MPesa Deposit REF123456 5,000.00 CR
15/06/2026 ATM Withdrawal 2,000.00 DR
20/06/2026 Bank Charge 50.00 DR
Closing Balance: 12,950.00
TXT;

        $service = app(BankStatementImportService::class);
        $parsed = $service->parseBankStatementText($text);
        $sum = round(array_sum(array_column($parsed['lines'], 'amount')), 2);
        $expectedClose = round((float) $parsed['opening_balance'] + $sum, 2);

        $this->assertSame('Equity', $parsed['bank']);
        $this->assertCount(3, $parsed['lines']);
        $this->assertSame(10000.0, (float) $parsed['opening_balance']);
        $this->assertSame(12950.0, (float) $parsed['closing_balance']);
        $this->assertEqualsWithDelta(12950.0, $expectedClose, 0.001);
        $this->assertEqualsWithDelta(2950.0, $sum, 0.001);
    }

    public function test_pdf_preview_rejects_scanned_empty_text(): void
    {
        // Minimal PDF with almost no extractable text content.
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
        $file = UploadedFile::fake()->createWithContent('scan.pdf', $pdf);

        $this->post('/api/v1/accounting/bank-statement-preview', [
            'file' => $file,
            'format' => 'pdf',
        ])->assertStatus(422);
    }

    public function test_ofx_preview_extracts_transactions(): void
    {
        $ofx = <<<'OFX'
OFXHEADER:100
DATA:OFXSGML
<OFX>
<BANKMSGSRSV1>
<STMTTRNRS>
<STMTRS>
<BANKACCTFROM><ORG>KCB BANK</ORG></BANKACCTFROM>
<BANKTRANLIST>
<DTSTART>20260601
<DTEND>20260630
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260615
<TRNAMT>2500.00
<FITID>FIT001
<NAME>Customer deposit
<MEMO>DEP-001
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260616
<TRNAMT>-100.00
<FITID>FIT002
<NAME>Bank fee
</STMTTRN>
</BANKTRANLIST>
<LEDGERBAL>
<BALAMT>2400.00
</LEDGERBAL>
</STMTRS>
</STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
OFX;

        $file = UploadedFile::fake()->createWithContent('statement.ofx', $ofx);

        $this->post('/api/v1/accounting/bank-statement-preview', [
            'file' => $file,
            'format' => 'ofx',
        ])->assertOk()
            ->assertJsonPath('line_count', 2)
            ->assertJsonPath('activity_sum', 2400);
    }

    public function test_apply_suggested_matches_and_clear_book_item(): void
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
            'entry_number' => 'JE-BANK-SUGGEST',
            'entry_date' => '2026-06-15',
            'description' => 'Customer deposit DEP-777',
            'created_by' => $this->user->id,
        ]);
        $entry->forceFill(['status' => 'posted', 'posted_at' => now()])->save();
        $bankLine = $entry->lines()->create([
            'account_id' => $bank->id,
            'debit' => 777,
            'credit' => 0,
            'line_notes' => 'Customer deposit DEP-777',
        ]);
        $entry->lines()->create([
            'account_id' => $sales->id,
            'debit' => 0,
            'credit' => 777,
        ]);

        $uncleared = JournalEntry::create([
            'organization_id' => $orgId,
            'entry_number' => 'JE-BANK-CLEAR',
            'entry_date' => '2026-06-20',
            'description' => 'Outstanding cheque',
            'created_by' => $this->user->id,
        ]);
        $uncleared->forceFill(['status' => 'posted', 'posted_at' => now()])->save();
        $unclearedLine = $uncleared->lines()->create([
            'account_id' => $bank->id,
            'debit' => 0,
            'credit' => 50,
            'line_notes' => 'Cheque CHQ-9',
        ]);
        $uncleared->lines()->create([
            'account_id' => $sales->id,
            'debit' => 50,
            'credit' => 0,
        ]);

        $created = $this->postJson('/api/v1/accounting/bank-reconciliations', [
            'chart_of_account_id' => $bank->id,
            'period_end' => '2026-06-30',
            'statement_balance' => 727,
            'statement_lines' => [
                [
                    'line_date' => '2026-06-15',
                    'description' => 'Customer deposit DEP-777',
                    'reference' => 'DEP-777',
                    'amount' => 777,
                ],
            ],
        ])->assertCreated();

        $reconciliationId = (int) $created->json('id');

        $this->postJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}/matches/apply-suggestions", [
            'min_confidence' => 50,
        ])->assertOk()
            ->assertJsonPath('applied', 1);

        $this->postJson("/api/v1/accounting/bank-reconciliations/{$reconciliationId}/clear-book-item", [
            'journal_entry_line_id' => $unclearedLine->id,
        ])->assertOk();

        $this->assertDatabaseHas('bank_reconciliation_matches', [
            'bank_reconciliation_id' => $reconciliationId,
            'journal_entry_line_id' => $bankLine->id,
        ]);
        $this->assertDatabaseHas('bank_reconciliation_matches', [
            'bank_reconciliation_id' => $reconciliationId,
            'journal_entry_line_id' => $unclearedLine->id,
            'bank_statement_line_id' => null,
        ]);
    }
}
