<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\User;

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

        // Previous-order edits bump content_revision per draft; each revision is a new sale.
        // New offline sales keep a stable uuid so timeout retries dedupe.
        if (
            array_key_exists('content_revision', $input)
            && str_starts_with($uuid, 'prev-edit-')
        ) {
            return $uuid.':'.(int) $input['content_revision'];
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
            ->where('cashier_id', (int) $user->id)
            ->where('fulfillment_meta->pos_sync_id', $syncId)
            ->orderByDesc('id')
            ->first();
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

        if (
            array_key_exists('content_revision', $input)
            && str_starts_with(trim((string) ($input['client_sale_uuid'] ?? '')), 'prev-edit-')
        ) {
            $fulfillmentMeta['pos_content_revision'] = (int) $input['content_revision'];
        }

        if (filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $fulfillmentMeta['pos_offline_checkout'] = true;
        }

        return $fulfillmentMeta;
    }
}
