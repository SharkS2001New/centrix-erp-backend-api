<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AccountingReportTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_trial_balance_and_profit_loss_gl_endpoints(): void
    {
        $cash = ChartOfAccount::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('account_code', '1000')
            ->firstOrFail();
        $sales = ChartOfAccount::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('account_code', '4000')
            ->firstOrFail();

        $entry = JournalEntry::create([
            'organization_id' => $this->user->organization_id,
            'entry_number' => 'JE-RPT-001',
            'entry_date' => '2026-06-15',
            'description' => 'Report test',
            'status' => 'posted',
            'created_by' => $this->user->id,
            'posted_at' => now(),
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 1000,
            'credit' => 0,
        ]);
        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $sales->id,
            'debit' => 0,
            'credit' => 1000,
        ]);

        $trial = $this->getJson('/api/v1/reports/trial-balance?to_date=2026-06-15')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('summary', $trial);
        $this->assertSame(1000.0, (float) $trial['summary']['total_debit']);
        $this->assertSame(1000.0, (float) $trial['summary']['total_credit']);

        $pl = $this->getJson('/api/v1/reports/profit-loss-gl?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()
            ->json();

        $this->assertSame(1000.0, (float) $pl['summary']['total_revenue']);
    }

    public function test_general_ledger_endpoint_returns_lines(): void
    {
        $this->getJson('/api/v1/reports/general-ledger?per_page=10')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_accounts_receivable_and_payable_endpoints(): void
    {
        $this->getJson('/api/v1/reports/accounts-receivable?per_page=10')->assertOk();
        $this->getJson('/api/v1/reports/accounts-payable?per_page=10')->assertOk();
    }
}
