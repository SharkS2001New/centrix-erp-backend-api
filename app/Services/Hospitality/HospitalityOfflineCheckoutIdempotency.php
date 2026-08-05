<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\User;

/**
 * Dedupe Hotel POS offline / local-first settle replays (timeout after success).
 */
class HospitalityOfflineCheckoutIdempotency
{
    public function syncId(array $input): ?string
    {
        $uuid = trim((string) ($input['client_check_uuid'] ?? ''));

        return $uuid !== '' ? $uuid : null;
    }

    public function findExisting(User $user, array $input): ?HospitalityCheck
    {
        $syncId = $this->syncId($input);
        if ($syncId === null || ! \Illuminate\Support\Facades\Schema::hasColumn('hospitality_checks', 'meta')) {
            return null;
        }

        return HospitalityCheck::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('opened_by', (int) $user->id)
            ->where('meta->pos_sync_id', $syncId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function stampMeta(array $meta, array $input): array
    {
        $syncId = $this->syncId($input);
        if ($syncId === null) {
            return $meta;
        }

        $meta['pos_sync_id'] = $syncId;
        $meta['client_check_uuid'] = trim((string) ($input['client_check_uuid'] ?? ''));

        if (filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $meta['pos_offline_checkout'] = true;
        }

        if (! empty($input['client_completed_at'])) {
            $meta['client_completed_at'] = (string) $input['client_completed_at'];
        }

        return $meta;
    }
}
