<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Services\Fulfillment\TripCodService;
use Tests\TestCase;

class TripCodServiceTest extends TestCase
{
  public function test_expected_at_depart_sums_unpaid_non_credit_orders(): void
    {
        $service = app(TripCodService::class);
        $settings = ['enable_cod_reconciliation' => true];

        $cod = new Sale([
            'order_total' => 1000,
            'amount_paid' => 200,
            'is_credit_sale' => false,
        ]);
        $credit = new Sale([
            'order_total' => 500,
            'amount_paid' => 0,
            'is_credit_sale' => true,
        ]);
        $prepaid = new Sale([
            'order_total' => 300,
            'amount_paid' => 300,
            'is_credit_sale' => false,
        ]);

        $this->assertSame(800.0, $service->expectedAtDepart(collect([$cod, $credit, $prepaid]), $settings));
    }

    public function test_outstanding_excludes_failed_deliveries_and_returns(): void
    {
        $service = app(TripCodService::class);
        $settings = ['enable_cod_reconciliation' => true];

        $failed = new Sale([
            'order_total' => 1000,
            'amount_paid' => 0,
            'status' => 'processed',
            'fulfillment_meta' => ['driver_delivery_outcome' => 'failed'],
        ]);
        $failed->id = 1;

        $delivered = new Sale([
            'order_total' => 800,
            'amount_paid' => 100,
            'status' => 'delivered',
            'fulfillment_meta' => [],
        ]);
        $delivered->id = 2;

        $total = $service->outstandingFromOrders(
            collect([$failed, $delivered]),
            $settings,
            [2 => 50.0],
        );

        $this->assertSame(650.0, $total);
    }
}
