<?php

namespace App\Services\Catalog;

use App\Events\OrgCatalogPricingUpdated;
use App\Models\User;
use App\Services\Notifications\InAppNotificationEvents;
use App\Services\Notifications\InAppNotificationService;
use App\Support\SalesReportUserScope;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

class CatalogPricingBroadcastService
{
    public function enabled(): bool
    {
        $connection = (string) config('broadcasting.default', 'null');

        return $connection !== '' && $connection !== 'null';
    }

    /**
     * Notify External POS / mobile clients that catalogue prices / markups changed.
     *
     * 1) Org channel — POS refreshes offline catalog + cart prices.
     * 2) Per-user in-app notifications — same Reverb path as approval popups
     *    (`notification.created` on `user.{id}`).
     *
     * @param  array{
     *     reason?: string,
     *     message?: string,
     *     product_code?: string|null,
     *     product_name?: string|null,
     *     route_id?: int|null,
     *     route_name?: string|null,
     *     actor_user_id?: int|null
     * }  $payload
     */
    public function notify(int $organizationId, array $payload = []): void
    {
        if ($organizationId <= 0) {
            return;
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            $message = 'Product prices or markups were updated.';
            $payload['message'] = $message;
        }

        if ($this->enabled()) {
            try {
                Broadcast::event(new OrgCatalogPricingUpdated($organizationId, $payload));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->notifySalesUsers($organizationId, $payload);
    }

    public function notifyProductPriceChanged(
        int $organizationId,
        string $productCode,
        ?string $productName = null,
        ?int $actorUserId = null,
    ): void {
        $label = trim((string) ($productName ?: $productCode));
        $this->notify($organizationId, [
            'reason' => 'product_price',
            'product_code' => $productCode,
            'product_name' => $productName,
            'actor_user_id' => $actorUserId,
            'message' => $label !== ''
                ? "Price updated: {$label}"
                : 'Product prices were updated.',
        ]);
    }

    public function notifyMarkupChanged(
        int $organizationId,
        string $productCode,
        ?string $productName = null,
        ?int $actorUserId = null,
    ): void {
        $label = trim((string) ($productName ?: $productCode));
        $this->notify($organizationId, [
            'reason' => 'markup',
            'product_code' => $productCode,
            'product_name' => $productName,
            'actor_user_id' => $actorUserId,
            'message' => $label !== ''
                ? "Markup updated: {$label}"
                : 'Product markups were updated.',
        ]);
    }

    public function notifyRouteMarkupChanged(
        int $organizationId,
        int $routeId,
        ?string $routeName = null,
        ?int $actorUserId = null,
    ): void {
        $label = trim((string) ($routeName ?: "Route #{$routeId}"));
        $this->notify($organizationId, [
            'reason' => 'route_markup',
            'route_id' => $routeId,
            'route_name' => $routeName,
            'actor_user_id' => $actorUserId,
            'message' => "Route markup updated: {$label}",
        ]);
    }

    /**
     * Fan-out cashier/mobile popups via the same in-app + Reverb user channel
     * used for discount approval outcomes.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function notifySalesUsers(int $organizationId, array $payload): void
    {
        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            return;
        }

        $reason = (string) ($payload['reason'] ?? 'pricing');
        $dedupeKey = 'catalog_pricing_notify:'.$organizationId.':'.md5(
            $reason.'|'.($payload['product_code'] ?? '').'|'.($payload['route_id'] ?? '').'|'.$message,
        );
        // Collapse rapid repeat edits of the same product/route within a short window.
        if (! Cache::add($dedupeKey, 1, now()->addSeconds(8))) {
            return;
        }

        $title = match ($reason) {
            'markup' => 'Markup updated',
            'route_markup' => 'Route markup updated',
            default => 'Price updated',
        };

        $actorUserId = isset($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : 0;

        $query = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('deleted_at');

        SalesReportUserScope::applyEligibleSalesReportUsers($query);

        if ($actorUserId > 0) {
            $query->whereKeyNot($actorUserId);
        }

        $recipients = $query->limit(200)->get(['id', 'organization_id']);
        if ($recipients->isEmpty()) {
            return;
        }

        $notifications = app(InAppNotificationService::class);
        foreach ($recipients as $recipient) {
            try {
                $notifications->createForUser($recipient, [
                    'organization_id' => $organizationId,
                    'type' => InAppNotificationEvents::CATALOG_PRICING,
                    'severity' => 'default',
                    'title' => $title,
                    'message' => $message,
                    // Visible in POS workspace filters; also reachable from mobile lists.
                    'action_url' => '/pos',
                    'created_by' => $actorUserId > 0 ? $actorUserId : null,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
