<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Dedupe External POS offline / local-first checkout replays (timeout after success).
 */
class PosOfflineCheckoutIdempotency
{
    public function syncId(array $input): ?string
    {
        $uuid = trim((string) ($input['client_sale_uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }

        // Offline / local-first sales and previous-order edits bump content_revision
        // when the cashier edits before sync. Include the revision so the latest
        // payload uploads as a fresh checkout key (not a frozen first attempt).
        // Same revision retries still dedupe on uuid:revision.
        if (array_key_exists('content_revision', $input)) {
            $offline = filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($offline || str_starts_with($uuid, 'prev-edit-')) {
                return $uuid.':'.(int) $input['content_revision'];
            }
        }

        return $uuid;
    }

    public function findExisting(User $user, array $input): ?Sale
    {
        $syncId = $this->syncId($input);
        if ($syncId === null) {
            return null;
        }

        return Sale::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('fulfillment_meta->pos_sync_id', $syncId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Serialize checkout attempts for the same offline sync key across workers/tabs.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public function runWithSyncLock(User $user, array $input, callable $callback)
    {
        $syncId = $this->syncId($input);
        if ($syncId === null) {
            return $callback();
        }

        $lockName = sprintf(
            'pos-offline-sync:%d:%s',
            (int) $user->organization_id,
            sha1((string) $syncId),
        );

        return Cache::lock($lockName, 20)->block(10, $callback);
    }

    /**
     * @param  array<string, mixed>  $fulfillmentMeta
     * @return array<string, mixed>
     */
    public function stampFulfillmentMeta(array $fulfillmentMeta, array $input): array
    {
        $syncId = $this->syncId($input);
        if ($syncId === null) {
            return $fulfillmentMeta;
        }

        $fulfillmentMeta['pos_sync_id'] = $syncId;
        $fulfillmentMeta['client_sale_uuid'] = trim((string) ($input['client_sale_uuid'] ?? ''));

        if (array_key_exists('content_revision', $input)) {
            $fulfillmentMeta['pos_content_revision'] = (int) $input['content_revision'];
        }

        if (filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $fulfillmentMeta['pos_offline_checkout'] = true;
        }

        return $fulfillmentMeta;
    }
}
