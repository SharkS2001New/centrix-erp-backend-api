<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerInvoiceFromSaleObserverTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_sale_create_syncs_customer_invoice_for_registered_customer(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $customer = Customer::firstOrFail();
        $product = Product::firstOrFail();

        $sale = Sale::create([
            'order_num' => 9001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 1500,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $this->assertDatabaseHas('customer_invoices', [
            'sale_id' => $sale->id,
            'customer_num' => $customer->customer_num,
            'invoice_total' => 1500,
            'payment_status' => 0,
        ]);
    }

    public function test_backfill_migration_creates_missing_invoices(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $customer = Customer::firstOrFail();

        $sale = Sale::withoutEvents(function () use ($admin, $customer) {
            return Sale::create([
                'order_num' => 9002,
                'branch_id' => $admin->branch_id,
                'organization_id' => $admin->organization_id,
                'channel' => 'backend',
                'cashier_id' => $admin->id,
                'customer_num' => $customer->customer_num,
                'status' => 'completed',
                'total_vat' => 0,
                'order_total' => 2200,
                'payment_status' => 'paid',
                'amount_paid' => 2200,
            ]);
        });

        $this->assertDatabaseMissing('customer_invoices', ['sale_id' => $sale->id]);

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_07_03_000001_backfill_customer_invoices_from_sales.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('customer_invoices', [
            'sale_id' => $sale->id,
            'customer_num' => $customer->customer_num,
            'invoice_total' => 2200,
            'payment_status' => 2,
        ]);
    }

    public function test_accounting_user_can_list_backfilled_invoices(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/customer-invoices')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_voiding_invoice_releases_ar_number_for_reuse(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $customer = Customer::firstOrFail();
        $service = app(CustomerInvoiceService::class);

        $firstSale = Sale::create([
            'order_num' => 33,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 1000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $voidedInvoice = CustomerInvoice::query()->where('sale_id', $firstSale->id)->firstOrFail();
        $this->assertSame('AR-33', $voidedInvoice->invoice_number);

        $service->voidForCancelledSale($firstSale, $admin);

        $voidedInvoice->refresh();
        $this->assertNotNull($voidedInvoice->deleted_at);
        $this->assertSame('AR-33-VOID-'.$voidedInvoice->id, $voidedInvoice->invoice_number);

        $secondSale = Sale::create([
            'order_num' => 33,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $customer->customer_num,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 2300,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        $this->assertDatabaseHas('customer_invoices', [
            'sale_id' => $secondSale->id,
            'invoice_number' => 'AR-33',
            'deleted_at' => null,
        ]);
    }

    public function test_allocate_renames_legacy_voided_invoice_blocking_number(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $customer = Customer::firstOrFail();
        $service = app(CustomerInvoiceService::class);

        $oldSale = Sale::withoutEvents(function () use ($admin, $customer) {
            return Sale::create([
                'order_num' => 44,
                'branch_id' => $admin->branch_id,
                'organization_id' => $admin->organization_id,
                'channel' => 'backend',
                'cashier_id' => $admin->id,
                'customer_num' => $customer->customer_num,
                'status' => 'cancelled',
                'total_vat' => 0,
                'order_total' => 500,
                'payment_status' => 'unpaid',
                'amount_paid' => 0,
            ]);
        });

        $legacyInvoice = CustomerInvoice::create([
            'invoice_number' => 'AR-44',
            'sale_id' => $oldSale->id,
            'customer_num' => $customer->customer_num,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'created_by' => $admin->id,
            'invoice_date' => now()->toDateString(),
            'total_vat' => 0,
            'invoice_total' => 500,
            'amount_paid' => 0,
            'payment_status' => 0,
            'deleted_at' => now()->toDateString(),
            'deleted_by' => $admin->id,
        ]);

        $newSale = Sale::withoutEvents(function () use ($admin, $customer) {
            return Sale::create([
                'order_num' => 44,
                'branch_id' => $admin->branch_id,
                'organization_id' => $admin->organization_id,
                'channel' => 'backend',
                'cashier_id' => $admin->id,
                'customer_num' => $customer->customer_num,
                'status' => 'processed',
                'total_vat' => 0,
                'order_total' => 800,
                'payment_status' => 'unpaid',
                'amount_paid' => 0,
            ]);
        });

        $invoice = $service->ensureForSale($newSale, $admin);

        $this->assertNotNull($invoice);
        $this->assertSame('AR-44', $invoice->invoice_number);
        $legacyInvoice->refresh();
        $this->assertSame('AR-44-VOID-'.$legacyInvoice->id, $legacyInvoice->invoice_number);
    }
}
