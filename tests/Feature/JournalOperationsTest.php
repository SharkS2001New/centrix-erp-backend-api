<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class JournalOperationsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_create_post_and_reverse_journal_entry(): void
    {
        $cash = ChartOfAccount::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('account_code', '1000')
            ->firstOrFail();
        $sales = ChartOfAccount::query()
            ->where('organization_id', $this->user->organization_id)
            ->where('account_code', '4000')
            ->firstOrFail();

        $created = $this->postJson('/api/v1/accounting/journal-entries', [
            'entry_number' => 'JE-TEST-001',
            'entry_date' => '2026-06-15',
            'description' => 'Cash sale',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0],
                ['account_id' => $sales->id, 'debit' => 0, 'credit' => 1000],
            ],
        ])->assertCreated();

        $entryId = $created->json('id');
        $this->assertSame('draft', $created->json('status'));

        $this->postJson("/api/v1/accounting/journal-entries/{$entryId}/post")
            ->assertOk()
            ->assertJsonPath('status', 'posted');

        $reverse = $this->postJson("/api/v1/accounting/journal-entries/{$entryId}/reverse")
            ->assertOk();

        $this->assertSame('void', $reverse->json('original.status'));
        $this->assertSame('posted', $reverse->json('reversal.status'));

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entryId,
            'status' => 'void',
        ]);
    }

    public function test_chart_of_accounts_index_includes_balance(): void
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
            'entry_number' => 'JE-BAL-001',
            'entry_date' => '2026-06-15',
            'description' => 'Balance test',
            'created_by' => $this->user->id,
        ]);
        $entry->forceFill([
            'status' => 'posted',
            'posted_at' => now(),
        ])->save();

        $entry->lines()->create([
            'account_id' => $cash->id,
            'debit' => 500,
            'credit' => 0,
        ]);
        $entry->lines()->create([
            'account_id' => $sales->id,
            'debit' => 0,
            'credit' => 500,
        ]);

        $response = $this->getJson('/api/v1/chart-of-accounts?per_page=200')->assertOk();
        $cashRow = collect($response->json('data'))->firstWhere('account_code', '1000');
        $salesRow = collect($response->json('data'))->firstWhere('account_code', '4000');

        $this->assertSame(500.0, (float) $cashRow['balance']);
        $this->assertSame(500.0, (float) $salesRow['balance']);
    }
}
