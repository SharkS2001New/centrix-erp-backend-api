<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OperationalAutoJournalTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_expense_create_posts_operating_expense_journal(): void
    {
        $expense = $this->postJson('/api/v1/expenses', [
            'branch_id' => $this->user->branch_id,
            'expense_group_id' => 1,
            'description' => 'Office supplies',
            'expense_amount' => 500,
            'expense_date' => now()->toDateString(),
            'payment_method_id' => 1,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('journal_entries', [
            'reference_type' => 'expense',
            'reference_id' => $expense['id'],
            'status' => 'posted',
        ]);
    }

    public function test_stock_receive_posts_inventory_and_ap_journal(): void
    {
        $productCode = Product::first()->product_code;

        $receipt = $this->postJson('/api/v1/inventory/receive', [
            'product_code' => $productCode,
            'branch_id' => $this->user->branch_id,
            'units_received' => 10,
            'cost_price' => 50,
            'stock_location' => 'store',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('journal_entries', [
            'reference_type' => 'stock_receipt',
            'reference_id' => $receipt['id'],
            'status' => 'posted',
        ]);
    }

    public function test_year_end_close_transfers_pl_to_retained_earnings(): void
    {
        $orgId = (int) $this->user->organization_id;
        $cash = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '1000')->firstOrFail();
        $sales = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '4000')->firstOrFail();
        $expense = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '5300')->firstOrFail();

        $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_number' => 'JE-REV-TEST',
            'entry_date' => '2026-03-01',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
        ])->assertCreated();
        $revId = JournalEntry::query()->where('entry_number', 'JE-REV-TEST')->value('id');
        $this->postJson("/api/v1/accounting/journal-entries/{$revId}/post")->assertOk();

        $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_number' => 'JE-EXP-TEST',
            'entry_date' => '2026-03-15',
            'lines' => [
                ['account_id' => $expense->id, 'debit' => 300, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => 300],
            ],
        ])->assertCreated();
        $expId = JournalEntry::query()->where('entry_number', 'JE-EXP-TEST')->value('id');
        $this->postJson("/api/v1/accounting/journal-entries/{$expId}/post")->assertOk();

        $response = $this->postJson('/api/v1/accounting/year-end-close', [
            'year' => 2026,
        ])->assertCreated();

        $response->assertJsonPath('net_income', 700);

        $this->assertDatabaseHas('journal_entries', [
            'entry_number' => 'YEAR-CLOSE-2026',
            'reference_type' => 'year_end_close',
            'status' => 'posted',
        ]);

        $closeEntry = JournalEntry::query()
            ->where('entry_number', 'YEAR-CLOSE-2026')
            ->firstOrFail();

        $this->assertGreaterThan(0, $closeEntry->lines()->count());
    }
}
