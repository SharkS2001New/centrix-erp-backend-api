<?php

namespace Tests\Unit;

use App\Models\DispatchTrip;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Fulfillment\PickingListBuilder;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PickingListBuilderTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_sync_picking_list_aggregates_by_product_and_sorts_by_shelf(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $productA = Product::query()->firstOrFail();
        $productB = Product::query()->where('product_code', '!=', $productA->product_code)->firstOrFail();

        $productA->update(['shelf_location' => 'B2']);
        $productB->update(['shelf_location' => 'A1']);

        $trip = DispatchTrip::query()->create([
            'branch_id' => $user->branch_id,
            'trip_code' => 'TRIP-PICK-TEST-001',
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $sale = Sale::query()->create([
            'order_num' => 996001,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 1000,
            'total_vat' => 0,
            'amount_paid' => 0,
            'archived' => 0,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $productA->product_code,
            'quantity' => 10,
            'amount' => 500,
            'product_vat' => 0,
            'discount_given' => 0,
        ]);
        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $productB->product_code,
            'quantity' => 5,
            'amount' => 500,
            'product_vat' => 0,
            'discount_given' => 0,
        ]);

        $trip->sales()->attach($sale->id, ['stop_seq' => 1]);

        $builder = app(PickingListBuilder::class);
        $pickingList = $builder->syncPickingList($trip->fresh(['sales', 'branch']));

        $this->assertSame('open', $pickingList->status);
        $this->assertStringStartsWith('PK-', $pickingList->list_number);
        $this->assertCount(2, $pickingList->lines);

        $lines = $pickingList->lines->values()->all();
        $this->assertSame($productB->product_code, $lines[0]->product_code);
        $this->assertSame('A1', $lines[0]->shelf_location);
        $this->assertSame(5.0, (float) $lines[0]->required_qty);
        $this->assertSame(5.0, (float) $lines[0]->picked_qty);

        $this->assertSame($productA->product_code, $lines[1]->product_code);
        $this->assertSame(10.0, (float) $lines[1]->required_qty);
    }

    public function test_update_picked_quantities_records_shortage(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $product = Product::query()->firstOrFail();
        $product->update(['shelf_location' => 'C4']);

        $trip = DispatchTrip::query()->create([
            'branch_id' => $user->branch_id,
            'trip_code' => 'TRIP-PICK-TEST-002',
            'scheduled_date' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $sale = Sale::query()->create([
            'order_num' => 996002,
            'branch_id' => $user->branch_id,
            'organization_id' => $user->organization_id,
            'channel' => 'mobile',
            'cashier_id' => $user->id,
            'status' => 'processed',
            'payment_status' => 'unpaid',
            'order_total' => 1000,
            'total_vat' => 0,
            'amount_paid' => 0,
            'archived' => 0,
        ]);

        SaleItem::query()->create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'amount' => 1000,
            'product_vat' => 0,
            'discount_given' => 0,
        ]);

        $trip->sales()->attach($sale->id, ['stop_seq' => 1]);

        $builder = app(PickingListBuilder::class);
        $pickingList = $builder->syncPickingList($trip->fresh(['sales', 'branch']));
        $line = $pickingList->lines->first();

        $updated = $builder->updatePickedQuantities($pickingList, [
            ['id' => $line->id, 'picked_qty' => 8, 'shortage_reason' => 'Damaged packs'],
        ]);

        $updatedLine = $updated->lines->first();
        $this->assertSame(8.0, (float) $updatedLine->picked_qty);
        $this->assertSame(2.0, (float) $updatedLine->shortage_qty);
        $this->assertSame('Damaged packs', $updatedLine->shortage_reason);
    }
}
