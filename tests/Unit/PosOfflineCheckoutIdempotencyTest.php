<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Sales\PosOfflineCheckoutIdempotency;
use Tests\TestCase;

class PosOfflineCheckoutIdempotencyTest extends TestCase
{
    public function test_sync_id_includes_content_revision_for_previous_order_edits(): void
    {
        $service = app(PosOfflineCheckoutIdempotency::class);

        $this->assertSame(
            'prev-edit-42:3',
            $service->syncId([
                'client_sale_uuid' => 'prev-edit-42',
                'content_revision' => 3,
            ]),
        );
    }

    public function test_sync_id_uses_uuid_only_for_new_sales(): void
    {
        $service = app(PosOfflineCheckoutIdempotency::class);

        $this->assertSame(
            '550e8400-e29b-41d4-a716-446655440000',
            $service->syncId([
                'client_sale_uuid' => '550e8400-e29b-41d4-a716-446655440000',
            ]),
        );
        // Content revision must not split new-sale retries into duplicates.
        $this->assertSame(
            '550e8400-e29b-41d4-a716-446655440000',
            $service->syncId([
                'client_sale_uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'content_revision' => 2,
            ]),
        );
    }

    public function test_stamp_fulfillment_meta_records_offline_sync_key(): void
    {
        $service = app(PosOfflineCheckoutIdempotency::class);
        $meta = $service->stampFulfillmentMeta([], [
            'client_sale_uuid' => 'prev-edit-9',
            'content_revision' => 2,
            'offline_order' => true,
        ]);

        $this->assertSame('prev-edit-9:2', $meta['pos_sync_id']);
        $this->assertSame('prev-edit-9', $meta['client_sale_uuid']);
        $this->assertSame(2, $meta['pos_content_revision']);
        $this->assertTrue($meta['pos_offline_checkout']);
    }
}
