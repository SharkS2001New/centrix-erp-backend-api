<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a pricing/markup change signal to External POS terminals for the
 * organization (catalog refresh + snackbar). In-app / mobile alerts are created
 * separately by CatalogPricingBroadcastService.
 */
class OrgCatalogPricingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public int $organizationId,
        public array $payload = [],
    ) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organization.'.$this->organizationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'catalog.pricing.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $message = trim((string) ($this->payload['message'] ?? ''));
        if ($message === '') {
            $message = 'Product prices or markups were updated.';
        }

        return [
            'organization_id' => $this->organizationId,
            'reason' => (string) ($this->payload['reason'] ?? 'pricing'),
            'message' => $message,
            'product_code' => $this->payload['product_code'] ?? null,
            'product_name' => $this->payload['product_name'] ?? null,
            'route_id' => $this->payload['route_id'] ?? null,
            'route_name' => $this->payload['route_name'] ?? null,
        ];
    }
}
