<?php

namespace Tests\Feature;

use App\Models\AccountingConnection;
use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ExternalAccountingTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());
    }

    public function test_external_mode_queues_sale_export_instead_of_native_journal(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'accounting_mode' => 'external',
            'accounting_provider' => 'quickbooks',
            'accounting_sync_direction' => 'export',
        ]);
        $org->update(['module_settings' => $settings]);

        AccountingConnection::create([
            'organization_id' => $org->id,
            'provider' => 'quickbooks',
            'realm_id' => '1234567890',
            'access_token' => 'stub-token',
            'status' => 'connected',
            'connected_at' => now(),
            'connected_by' => $user->id,
        ]);

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
            'submit_kra' => false,
        ])->assertCreated()->json();

        $this->assertDatabaseMissing('journal_entries', [
            'reference_type' => 'sale',
            'reference_id' => $sale['id'],
        ]);

        $this->assertDatabaseHas('accounting_export_queue', [
            'organization_id' => $org->id,
            'provider' => 'quickbooks',
            'reference_type' => 'sale',
            'reference_id' => $sale['id'],
            'status' => 'pending',
        ]);
    }

    public function test_export_queue_processing_is_idempotent(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $user->organization_id;

        AccountingConnection::create([
            'organization_id' => $orgId,
            'provider' => 'quickbooks',
            'realm_id' => '1234567890',
            'access_token' => 'stub-token',
            'status' => 'connected',
            'connected_at' => now(),
            'connected_by' => $user->id,
        ]);

        AccountingExportQueue::create([
            'organization_id' => $orgId,
            'provider' => 'quickbooks',
            'entry_number' => 'SALE-99999',
            'entry_date' => now()->toDateString(),
            'reference_type' => 'sale',
            'reference_id' => 99999,
            'description' => 'Test sale',
            'lines' => [
                ['account_code' => '1000', 'debit' => 100, 'credit' => 0],
                ['account_code' => '4000', 'debit' => 0, 'credit' => 100],
            ],
            'status' => 'pending',
        ]);

        $this->putJson('/api/v1/accounting/account-mappings', [
            'mappings' => [
                ['local_account_code' => '1000', 'external_account_id' => 'QB-1000', 'external_account_name' => 'Cash'],
                ['local_account_code' => '4000', 'external_account_id' => 'QB-4000', 'external_account_name' => 'Sales'],
            ],
        ])->assertOk();

        $first = $this->postJson('/api/v1/accounting/export-queue/process')->assertOk()->json();
        $this->assertSame(1, $first['exported']);

        $this->assertDatabaseHas('accounting_export_queue', [
            'reference_type' => 'sale',
            'reference_id' => 99999,
            'status' => 'exported',
        ]);

        $second = $this->postJson('/api/v1/accounting/export-queue/process')->assertOk()->json();
        $this->assertSame(0, $second['processed']);
    }

    public function test_integration_status_endpoint(): void
    {
        $this->getJson('/api/v1/accounting/integration/status')
            ->assertOk()
            ->assertJsonStructure([
                'accounting_mode',
                'accounting_provider',
                'sync_direction',
                'connection',
                'pending_exports',
            ]);
    }

    public function test_live_quickbooks_export_calls_journal_entry_api(): void
    {
        config([
            'quickbooks.client_id' => 'test-client',
            'quickbooks.client_secret' => 'test-secret',
            'quickbooks.api_base_url' => 'https://sandbox-quickbooks.api.intuit.com',
        ]);

        Http::fake([
            'sandbox-quickbooks.api.intuit.com/*' => Http::response([
                'JournalEntry' => ['Id' => '12345'],
            ], 200),
        ]);

        $user = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $user->organization_id;

        AccountingConnection::create([
            'organization_id' => $orgId,
            'provider' => 'quickbooks',
            'realm_id' => '9876543210',
            'access_token' => 'live-access-token',
            'refresh_token' => 'live-refresh-token',
            'token_expires_at' => now()->addHour(),
            'status' => 'connected',
            'connected_at' => now(),
            'connected_by' => $user->id,
        ]);

        AccountingExportQueue::create([
            'organization_id' => $orgId,
            'provider' => 'quickbooks',
            'entry_number' => 'SALE-LIVE-001',
            'entry_date' => now()->toDateString(),
            'reference_type' => 'sale',
            'reference_id' => 88888,
            'description' => 'Live export test',
            'lines' => [
                ['account_code' => '1000', 'debit' => 100, 'credit' => 0],
                ['account_code' => '4000', 'debit' => 0, 'credit' => 100],
            ],
            'status' => 'pending',
        ]);

        $this->putJson('/api/v1/accounting/account-mappings', [
            'mappings' => [
                ['local_account_code' => '1000', 'external_account_id' => '39', 'external_account_name' => 'Cash'],
                ['local_account_code' => '4000', 'external_account_id' => '41', 'external_account_name' => 'Sales'],
            ],
        ])->assertOk();

        $result = $this->postJson('/api/v1/accounting/export-queue/process')->assertOk()->json();
        $this->assertSame(1, $result['exported']);

        $this->assertDatabaseHas('accounting_export_queue', [
            'reference_id' => 88888,
            'status' => 'exported',
            'external_journal_id' => 'QBO-12345',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/journalentry')
                && $request->method() === 'POST';
        });
    }

    public function test_unsupported_provider_export_is_rejected(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $user->organization_id;

        AccountingExportQueue::create([
            'organization_id' => $orgId,
            'provider' => 'xero',
            'entry_number' => 'SALE-XERO-001',
            'entry_date' => now()->toDateString(),
            'reference_type' => 'sale',
            'reference_id' => 77777,
            'description' => 'Unsupported provider',
            'lines' => [
                ['account_code' => '1000', 'debit' => 50, 'credit' => 0],
                ['account_code' => '4000', 'debit' => 0, 'credit' => 50],
            ],
            'status' => 'pending',
        ]);

        $this->postJson('/api/v1/accounting/export-queue/process', [
            'provider' => 'xero',
        ])->assertOk()
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('exported', 0);
    }
}
