<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class FiscalPeriodTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_closed_fiscal_period_blocks_journal_posting(): void
    {
        $orgId = (int) $this->user->organization_id;
        $period = FiscalPeriod::create([
            'organization_id' => $orgId,
            'period_name' => 'Test Month',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ]);

        $cash = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '1000')->firstOrFail();
        $sales = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '4000')->firstOrFail();

        $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_number' => 'JE-CLOSED-PERIOD',
            'entry_date' => '2026-01-15',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 100, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 100],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['entry_date']);

        $this->assertDatabaseMissing('journal_entries', ['entry_number' => 'JE-CLOSED-PERIOD']);
    }

    public function test_admin_can_close_and_reopen_fiscal_period(): void
    {
        $orgId = (int) $this->user->organization_id;
        $period = FiscalPeriod::create([
            'organization_id' => $orgId,
            'period_name' => 'Open Month',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'status' => 'open',
        ]);

        $this->postJson("/api/v1/accounting/fiscal-periods/{$period->id}/close")
            ->assertOk()
            ->assertJsonPath('status', 'closed');

        $this->postJson("/api/v1/accounting/fiscal-periods/{$period->id}/reopen")
            ->assertOk()
            ->assertJsonPath('status', 'open');
    }

    public function test_close_fiscal_period_blocked_when_draft_journals_exist(): void
    {
        $orgId = (int) $this->user->organization_id;
        $period = FiscalPeriod::create([
            'organization_id' => $orgId,
            'period_name' => 'Draft Month',
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'status' => 'open',
        ]);

        $cash = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '1000')->firstOrFail();
        $sales = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '4000')->firstOrFail();

        $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_number' => 'JE-DRAFT-BLOCK',
            'entry_date' => '2026-03-15',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 50, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 50],
            ],
        ])->assertCreated();

        $this->postJson("/api/v1/accounting/fiscal-periods/{$period->id}/close")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
    }
}
