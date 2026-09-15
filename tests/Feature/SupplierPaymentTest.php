<?php

namespace Tests\Feature;

use App\Models\LpoMst;
use App\Models\LpoTxn;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SupplierPaymentTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function createReceivedLpo(User $admin, Supplier $supplier, string $reference): LpoMst
    {
        $orgId = (int) $admin->organization_id;
        $nextSeq = (int) LpoMst::query()->where('organization_id', $orgId)->max('lpo_seq') + 1;

        $lpo = LpoMst::create([
            'organization_id' => $orgId,
            'lpo_seq' => $nextSeq,
            'supplier_id' => $supplier->id,
            'reference_number' => $reference,
            'total_amount' => 1000,
            'net_amount' => 1000,
            'created_by' => $admin->id,
            'created_at' => now(),
            'lpo_status_code' => 1,
            'cleared_flag' => 0,
        ]);

        LpoTxn::create([
            'lpo_no' => $lpo->lpo_no,
            'product_code' => '6161100100015',
            'ordered_qty' => 10,
            'received_qty' => 10,
            'cost_price' => 100,
            'uom' => 'kg',
        ]);

        return $lpo;
    }

    public function test_admin_can_record_supplier_payment_against_lpo(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $lpo = $this->createReceivedLpo($admin, $supplier, 'PO-TEST-001');

        $method = PaymentMethod::query()->firstOrFail();

        $response = $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", [
            'lpo_no' => $lpo->lpo_no,
            'payment_method_id' => $method->id,
            'amount_paid' => 500,
            'manual_amount' => false,
            'amount_due_snapshot' => 1000,
            'date_paid' => '2026-06-10',
            'notes' => 'Partial test payment',
        ]);

        $response->assertCreated()
            ->assertJsonPath('amount_paid', 500)
            ->assertJsonPath('lpo_no', $lpo->lpo_no);

        $this->assertDatabaseHas('supplier_payments', [
            'supplier_id' => $supplier->id,
            'lpo_no' => $lpo->lpo_no,
            'amount_paid' => 500,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'reference_type' => 'supplier_payment',
            'status' => 'posted',
        ]);

        $summary = $this->getJson("/api/v1/suppliers/{$supplier->id}/summary")
            ->assertOk()
            ->json();

        $purchase = collect($summary['purchases'])->firstWhere('lpo_no', $lpo->lpo_no);
        $this->assertNotNull($purchase);
        $this->assertEquals(500, $purchase['amount_paid']);
        $this->assertEquals(500, $purchase['balance_due']);
    }

    public function test_full_lpo_payment_marks_lpo_cleared(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $lpo = $this->createReceivedLpo($admin, $supplier, 'PO-TEST-FULL');

        $method = PaymentMethod::query()->firstOrFail();

        $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", [
            'lpo_no' => $lpo->lpo_no,
            'payment_method_id' => $method->id,
            'amount_paid' => 1000,
            'manual_amount' => false,
            'amount_due_snapshot' => 1000,
            'date_paid' => '2026-06-10',
        ])->assertCreated();

        $lpo->refresh();
        $this->assertSame(1, (int) $lpo->cleared_flag);
        $this->assertSame(6, (int) $lpo->lpo_status_code);
        $this->assertNotNull($lpo->cleared_at);
    }

    public function test_future_date_paid_is_rejected(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $method = PaymentMethod::query()->firstOrFail();

        $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", [
            'payment_method_id' => $method->id,
            'amount_paid' => 100,
            'manual_amount' => true,
            'declared_payable' => 100,
            'date_paid' => now()->addDays(10)->toDateString(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['date_paid']);
    }

    public function test_supplier_payment_can_be_deleted(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $method = PaymentMethod::query()->firstOrFail();

        $payment = $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", [
            'payment_method_id' => $method->id,
            'amount_paid' => 250,
            'manual_amount' => true,
            'declared_payable' => 250,
            'amount_due_snapshot' => 250,
            'date_paid' => now()->toDateString(),
            'notes' => 'Mistaken payment',
        ])->assertCreated()->json();

        $this->assertDatabaseHas('supplier_payments', ['id' => $payment['id']]);

        $this->deleteJson('/api/v1/supplier-payments/'.$payment['id'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('supplier_payments', ['id' => $payment['id']]);
    }

    public function test_supplier_payments_index_returns_recorded_payment(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $supplier = Supplier::where('supplier_code', 'SUP-001')->firstOrFail();
        $method = PaymentMethod::query()->firstOrFail();

        $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", [
            'payment_method_id' => $method->id,
            'amount_paid' => 200,
            'manual_amount' => true,
            'declared_payable' => 200,
            'amount_due_snapshot' => 200,
            'date_paid' => '2026-06-10',
        ])->assertCreated();

        $this->getJson('/api/v1/supplier-payments?supplier_id='.$supplier->id)
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonFragment(['supplier_id' => $supplier->id, 'amount_paid' => 200]);
    }
}
