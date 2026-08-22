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
    /** Wait for a paired markup/price save before notifying External POS. */
    private const MERGE_WAIT_SECONDS = 2;

    public function enabled(): bool
    {
        $connection = (string) config('broadcasting.default', 'null');

        return $connection !== '' && $connection !== 'null';
    }

    /**
     * Notify External POS / mobile / managers that catalogue prices changed.
     *
     * @param  array{
     *     reason?: string,
     *     message?: string,
     *     product_code?: string|null,
     *     product_name?: string|null,
     *     price_from?: float|null,
     *     price_to?: float|null,
     *     markup_to?: float|null,
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

        $this->recordPricingRevision($organizationId, $payload);

        if ($this->enabled()) {
            try {
                Broadcast::event(new OrgCatalogPricingUpdated($organizationId, $payload));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // In-app fan-out must not depend on Reverb — mobile polls these rows.
        $this->notifySalesUsers($organizationId, $payload);
    }

    public function notifyProductPriceChanged(
        int $organizationId,
        string $productCode,
        ?string $productName = null,
        ?int $actorUserId = null,
        ?float $previousUnitPrice = null,
        ?float $newUnitPrice = null,
    ): void {
        $this->accumulateProductChange($organizationId, $productCode, $productName, $actorUserId, [
            'price_from' => $previousUnitPrice,
            'price_to' => $newUnitPrice,
        ]);
    }

    public function notifyMarkupChanged(
        int $organizationId,
        string $productCode,
        ?string $productName = null,
        ?int $actorUserId = null,
        ?float $previousMarkupPrice = null,
        ?float $newMarkupPrice = null,
    ): void {
        $this->accumulateProductChange($organizationId, $productCode, $productName, $actorUserId, [
            'markup_from' => $previousMarkupPrice,
            'markup_to' => $newMarkupPrice,
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
     * @param  array<string, mixed>  $pending
     */
    public function flushMergedProductChange(int $organizationId, array $pending): void
    {
        $message = $this->buildProductPricingMessage($pending);
        $hasPrice = $this->hasPriceChange($pending);
        $hasMarkup = $this->hasMarkupChange($pending);

        $reason = match (true) {
            $hasPrice && $hasMarkup => 'product_price_and_markup',
            $hasMarkup => 'markup',
            default => 'product_price',
        };

        $this->notify($organizationId, [
            'reason' => $reason,
            'message' => $message,
            'product_code' => $pending['product_code'] ?? null,
            'product_name' => $pending['product_name'] ?? null,
            'price_from' => $pending['price_from'] ?? null,
            'price_to' => $pending['price_to'] ?? null,
            'markup_to' => $pending['markup_to'] ?? null,
            'actor_user_id' => $pending['actor_user_id'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    public function buildProductPricingMessage(array $pending): string
    {
        $name = trim((string) ($pending['product_name'] ?? $pending['product_code'] ?? 'Item'));
        $hasPrice = $this->hasPriceChange($pending);
        $hasMarkup = $this->hasMarkupChange($pending);
        $markupTo = isset($pending['markup_to']) ? (float) $pending['markup_to'] : null;

        if ($hasPrice && $hasMarkup && $markupTo !== null) {
            return sprintf(
                'Price of %s has been updated from %s to %s, Retail markup of %s',
                $name,
                $this->formatKes((float) $pending['price_from']),
                $this->formatKes((float) $pending['price_to']),
                $this->formatKes($markupTo),
            );
        }

        if ($hasPrice) {
            return sprintf(
                'Price of %s has been updated from %s to %s',
                $name,
                $this->formatKes((float) $pending['price_from']),
                $this->formatKes((float) $pending['price_to']),
            );
        }

        if ($hasMarkup && $markupTo !== null) {
            return sprintf(
                'Retail markup of %s for %s',
                $this->formatKes($markupTo),
                $name,
            );
        }

        return 'Product prices or markups were updated.';
    }

    public function formatKes(float $amount): string
    {
        return 'KES '.number_format($amount, 2, '.', ',');
    }

    /**
     * @param  array<string, mixed>  $delta
     */
    protected function accumulateProductChange(
        int $organizationId,
        string $productCode,
        ?string $productName,
        ?int $actorUserId,
        array $delta,
    ): void {
        $mergeKey = $this->mergeCacheKey($organizationId, $productCode);
        $scheduleKey = $this->mergeScheduleKey($organizationId, $productCode);

        $pending = Cache::get($mergeKey, []);
        if (! is_array($pending)) {
            $pending = [];
        }

        $pending['product_code'] = $productCode;
        if ($productName) {
            $pending['product_name'] = $productName;
        }
        foreach ($delta as $key => $value) {
            if ($value !== null) {
                $pending[$key] = $value;
            }
        }
        if ($actorUserId) {
            $pending['actor_user_id'] = $actorUserId;
        }

        Cache::put($mergeKey, $pending, now()->addSeconds(self::MERGE_WAIT_SECONDS + 10));

        if (app()->runningUnitTests()) {
            $this->flushMergedProductChange($organizationId, $pending);

            return;
        }

        if (! Cache::add($scheduleKey, 1, now()->addSeconds(self::MERGE_WAIT_SECONDS + 15))) {
            return;
        }

        dispatch(function () use ($organizationId, $productCode, $mergeKey, $scheduleKey) {
            sleep(self::MERGE_WAIT_SECONDS);
            Cache::forget($scheduleKey);
            $merged = Cache::pull($mergeKey);
            if (! is_array($merged) || $merged === []) {
                return;
            }
            app(self::class)->flushMergedProductChange($organizationId, $merged);
        })->afterResponse();
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    protected function hasPriceChange(array $pending): bool
    {
        if (! array_key_exists('price_from', $pending) || ! array_key_exists('price_to', $pending)) {
            return false;
        }

        return round((float) $pending['price_from'], 2) !== round((float) $pending['price_to'], 2);
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    protected function hasMarkupChange(array $pending): bool
    {
        if (! array_key_exists('markup_to', $pending)) {
            return false;
        }

        if (! array_key_exists('markup_from', $pending)) {
            return true;
        }

        return round((float) $pending['markup_from'], 2) !== round((float) $pending['markup_to'], 2);
    }

    protected function mergeCacheKey(int $organizationId, string $productCode): string
    {
        return 'catalog_pricing_merge:'.$organizationId.':'.mb_strtolower(trim($productCode));
    }

    protected function mergeScheduleKey(int $organizationId, string $productCode): string
    {
        return 'catalog_pricing_merge_scheduled:'.$organizationId.':'.mb_strtolower(trim($productCode));
    }

    /**
     * Org-wide revision counter — External POS polls this (no Reverb or per-user rows required).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function recordPricingRevision(int $organizationId, array $payload): int
    {
        $revisionKey = 'catalog_pricing_revision:'.$organizationId;
        $latestKey = 'catalog_pricing_latest:'.$organizationId;

        if (! Cache::has($revisionKey)) {
            Cache::put($revisionKey, 0, now()->addDays(30));
        }

        $revision = (int) Cache::increment($revisionKey);
        Cache::put($latestKey, [
            'revision' => $revision,
            'reason' => (string) ($payload['reason'] ?? 'pricing'),
            'message' => trim((string) ($payload['message'] ?? '')),
            'product_code' => isset($payload['product_code']) ? (string) $payload['product_code'] : null,
            'product_name' => isset($payload['product_name']) ? (string) $payload['product_name'] : null,
            'price_from' => $payload['price_from'] ?? null,
            'price_to' => $payload['price_to'] ?? null,
            'markup_to' => $payload['markup_to'] ?? null,
            'route_id' => isset($payload['route_id']) ? (int) $payload['route_id'] : null,
            'route_name' => isset($payload['route_name']) ? (string) $payload['route_name'] : null,
            'updated_at' => now()->toIso8601String(),
        ], now()->addDays(7));

        return $revision;
    }

    public function currentRevision(int $organizationId): int
    {
        if ($organizationId <= 0) {
            return 0;
        }

        return (int) Cache::get('catalog_pricing_revision:'.$organizationId, 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRevisionPayload(int $organizationId): ?array
    {
        if ($organizationId <= 0) {
            return null;
        }

        $payload = Cache::get('catalog_pricing_latest:'.$organizationId);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Fan-out cashier / mobile / updater alerts via in-app + user Reverb channel.
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
            'product_price_and_markup' => 'Price & markup updated',
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

        $recipientIds = $query->limit(200)->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Always include the updater so they see the same confirmation alert.
        if ($actorUserId > 0 && ! in_array($actorUserId, $recipientIds, true)) {
            $recipientIds[] = $actorUserId;
        }

        if ($recipientIds === []) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $recipientIds)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get(['id', 'organization_id']);

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
