<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CustomerStatementReturnTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        $this->ensureActiveSubscription($this->user);
        Sanctum::actingAs($this->user);
    }

    protected function ensureActiveSubscription(User $user): void
    {
        $org = Organization::query()->find($user->organization_id);
        if (! $org) {
            return;
        }

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $org->id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_customer_statement_shows_original_invoice_and_return_credit(): void
    {
        $customer = Customer::query()
            ->where('organization_id', $this->user->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();
        $product = Product::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 58168,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'backend',
            'cashier_id' => $this->user->id,
            'customer_num' => $customer->customer_num,
            'status' => 'completed',
            'completed_at' => '2026-07-11 08:15:00',
            'total_vat' => 0,
            'order_total' => 42000,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'line_no' => 1,
            'product_code' => $product->product_code,
            'quantity' => 100,
            'selling_price' => 420,
            'amount' => 42000,
            'product_vat' => 0,
            'discount_given' => 0,
        ]);

        $invoice = app(CustomerInvoiceService::class)->ensureForSale($sale->fresh(), $this->user);
        $this->assertNotNull($invoice);
        $this->assertSame(42000.0, (float) $invoice->invoice_total);

        $created = $this->postJson('/api/v1/customer-returns', [
            'sale_id' => $sale->id,
            'return_date' => '2026-07-12',
            'refund_method' => 'CREDIT',
            'reason' => 'Damaged Product',
            'lines' => [
                [
                    'product_code' => $product->product_code,
                    'product_name' => $product->product_name,
                    'quantity_sold' => 100,
                    'return_qty' => 20,
                    'unit_price' => 420,
                    'amount' => 8400,
                    'uom' => 'CARTON',
                ],
            ],
        ])->assertCreated();

        $returnId = (int) $created->json('id');
        $this->postJson("/api/v1/customer-returns/{$returnId}/approve")
            ->assertOk();

        $sale->refresh();
        $invoice->refresh();
        $creditNote = CreditNote::query()->where('customer_return_id', $returnId)->first();
        $this->assertNotNull($creditNote);
        $this->assertSame(8400.0, (float) $creditNote->total_amount);
        $this->assertSame(33600.0, (float) $sale->order_total);
        $this->assertSame(42000.0, (float) $invoice->invoice_total);

        $paymentMethodId = (int) (\DB::table('payment_methods')->value('id') ?? 1);
        CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $customer->customer_num,
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'payment_method_id' => $paymentMethodId,
            'amount_paid' => 33600,
            'date_paid' => '2026-07-15',
            'reference_number' => 'UGD5KBC6HP',
            'received_by' => $this->user->id,
        ]);
        $invoice->update([
            'amount_paid' => 33600,
            'payment_status' => 2,
        ]);

        $statement = $this->getJson("/api/v1/reports/customers/{$customer->customer_num}/statement")
            ->assertOk()
            ->json();

        $invoiceRow = collect($statement['invoices'] ?? [])->firstWhere('id', $invoice->id);
        $creditRow = collect($statement['credit_notes'] ?? [])->firstWhere('id', $creditNote->id);

        $this->assertNotNull($invoiceRow);
        $this->assertNotNull($creditRow);
        $this->assertSame(42000.0, (float) $invoiceRow['statement_debit']);
        $this->assertSame(8400.0, (float) $creditRow['total_amount']);
        $this->assertGreaterThanOrEqual(42000.0, (float) data_get($statement, 'summary.total_invoiced'));
        $this->assertGreaterThanOrEqual(8400.0, (float) data_get($statement, 'summary.total_credits'));
    }

    public function test_statement_reconstructs_gross_when_invoice_was_previously_netted(): void
    {
        $customer = Customer::query()
            ->where('organization_id', $this->user->organization_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $sale = Sale::create([
            'order_num' => 58169,
            'branch_id' => $this->user->branch_id,
            'organization_id' => $this->user->organization_id,
            'channel' => 'backend',
            'cashier_id' => $this->user->id,
            'customer_num' => $customer->customer_num,
            'status' => 'completed',
            'completed_at' => '2026-07-11 08:15:00',
            'total_vat' => 0,
            'order_total' => 42000,
            'payment_status' => 'paid',
            'amount_paid' => 42000,
        ]);

        $invoice = CustomerInvoice::query()->where('sale_id', $sale->id)->firstOrFail();
        // Simulate legacy netting: AR total shrunk with the sale after a return.
        Sale::withoutEvents(function () use ($sale) {
            $sale->update(['order_total' => 33600, 'amount_paid' => 33600]);
        });
        $invoice->update([
            'invoice_total' => 33600,
            'amount_paid' => 33600,
            'payment_status' => 2,
        ]);

        $returnId = DB::table('customer_returns')->insertGetId([
            'return_no' => 'RTN-TEST-58169',
            'return_seq' => 58169,
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'sale_id' => $sale->id,
            'customer_num' => $customer->customer_num,
            'return_date' => '2026-07-12',
            'refund_method' => 'CREDIT',
            'reason' => 'Damaged Product',
            'status' => 'approved',
            'total_amount' => 8400,
            'returned_by' => $this->user->id,
            'approved_by' => $this->user->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('credit_notes')->insert([
            'credit_note_no' => 'CN-TEST-58169',
            'customer_return_id' => $returnId,
            'organization_id' => $this->user->organization_id,
            'branch_id' => $this->user->branch_id,
            'sale_id' => $sale->id,
            'customer_num' => $customer->customer_num,
            'credit_date' => '2026-07-12',
            'total_amount' => 8400,
            'refund_method' => 'CREDIT',
            'reason' => 'Damaged Product',
            'kra_status' => 'skipped',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $statement = $this->getJson("/api/v1/reports/customers/{$customer->customer_num}/statement")
            ->assertOk()
            ->json();

        $row = collect($statement['invoices'] ?? [])->firstWhere('id', $invoice->id);
        $this->assertNotNull($row);
        $this->assertSame(42000.0, (float) $row['statement_debit']);
        $this->assertSame(8400.0, (float) data_get($statement, 'summary.total_credits'));
    }
}
