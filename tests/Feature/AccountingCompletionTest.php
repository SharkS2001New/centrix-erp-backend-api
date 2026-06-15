<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\StockTakeLine;
use App\Models\StockTakeSession;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AccountingCompletionTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected string $productCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);

        $product = Product::firstOrFail();
        $product->update(['last_cost_price' => 40]);
        $this->productCode = $product->product_code;
    }

    public function test_checkout_journal_includes_cogs_and_inventory_lines(): void
    {
        $orgId = (int) $this->user->organization_id;
        $cogs = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '5000')->firstOrFail();
        $inventory = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '1300')->firstOrFail();

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 2,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'payment_method_code' => 'CASH',
        ])->assertCreated()->json();

        $entry = JournalEntry::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale['id'])
            ->where('status', 'posted')
            ->firstOrFail();

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $cogs->id,
            'debit' => 80,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $inventory->id,
            'debit' => 0,
            'credit' => 80,
        ]);
    }

    public function test_cancelled_held_sale_reverses_posted_journal(): void
    {
        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $this->user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $this->productCode,
            'quantity' => 1,
        ])->assertCreated();

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'held',
            'pay_now' => 0,
            'save_only' => true,
        ])->assertCreated()->json();

        $original = JournalEntry::query()
            ->where('reference_type', 'sale')
            ->where('reference_id', $sale['id'])
            ->where('status', 'posted')
            ->firstOrFail();

        $this->postJson("/api/v1/sales/orders/{$sale['id']}/cancel-held")->assertOk();

        $original->refresh();
        $this->assertSame('void', $original->status);

        $this->assertTrue(
            JournalEntry::query()
                ->where('reference_type', 'journal_reversal')
                ->where('reference_id', $original->id)
                ->where('status', 'posted')
                ->exists()
        );
    }

    public function test_stock_take_completion_posts_inventory_adjustment_journal(): void
    {
        $orgId = (int) $this->user->organization_id;
        $cogs = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '5000')->firstOrFail();
        $inventory = ChartOfAccount::query()->where('organization_id', $orgId)->where('account_code', '1300')->firstOrFail();

        $session = StockTakeSession::create([
            'branch_id' => $this->user->branch_id,
            'session_code' => 'ST-TEST-001',
            'status' => 'in_progress',
            'stock_location' => 'shop',
            'started_by' => $this->user->id,
        ]);

        StockTakeLine::create([
            'session_id' => $session->id,
            'product_code' => $this->productCode,
            'stock_location' => 'shop',
            'system_quantity' => 10,
            'counted_quantity' => 8,
        ]);

        $this->postJson("/api/v1/inventory/stock-take/{$session->id}/complete")->assertOk();

        $entry = JournalEntry::query()
            ->where('reference_type', 'stock_take_session')
            ->where('reference_id', $session->id)
            ->where('status', 'posted')
            ->firstOrFail();

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $cogs->id,
            'debit' => 80,
            'credit' => 0,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $inventory->id,
            'debit' => 0,
            'credit' => 80,
        ]);

        $this->assertSame(
            80.0,
            (float) JournalEntryLine::query()
                ->where('journal_entry_id', $entry->id)
                ->sum('debit')
        );
    }
}
