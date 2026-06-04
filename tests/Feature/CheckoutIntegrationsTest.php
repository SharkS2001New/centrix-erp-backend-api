<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\KraResponse;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CheckoutIntegrationsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());
    }

    public function test_checkout_queues_kra_and_posts_journal(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $productCode = Product::first()->product_code;

        $cartId = $this->postJson('/api/v1/sales/carts', [
            'channel' => 'pos',
            'branch_id' => $user->branch_id,
        ])->json('id');

        $this->postJson("/api/v1/sales/carts/{$cartId}/lines", [
            'product_code' => $productCode,
            'quantity' => 1,
        ]);

        $sale = $this->postJson("/api/v1/sales/carts/{$cartId}/checkout", [
            'status' => 'completed',
            'submit_kra' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('kra_responses', [
            'sale_id' => $sale['id'],
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'reference_type' => 'sale',
            'reference_id' => $sale['id'],
            'status' => 'posted',
        ]);

        $this->assertGreaterThan(0, JournalEntry::where('reference_id', $sale['id'])->count());
    }
}
