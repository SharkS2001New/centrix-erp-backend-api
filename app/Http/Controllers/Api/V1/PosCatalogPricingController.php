<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogPricingBroadcastService;
use Illuminate\Http\Request;

/**
 * Lightweight poll target for External POS price / markup toasts (no Reverb required).
 */
class PosCatalogPricingController extends Controller
{
    public function __invoke(Request $request, CatalogPricingBroadcastService $pricing)
    {
        $orgId = (int) ($request->user()?->organization_id ?? 0);
        $since = max(0, (int) $request->query('since', 0));
        $current = $pricing->currentRevision($orgId);

        if ($current <= $since) {
            return response()->json([
                'revision' => $current,
                'changed' => false,
            ]);
        }

        $latest = $pricing->latestRevisionPayload($orgId) ?? [];

        return response()->json([
            'revision' => $current,
            'changed' => true,
            'reason' => (string) ($latest['reason'] ?? 'pricing'),
            'message' => trim((string) ($latest['message'] ?? '')) ?: 'Product prices or markups were updated.',
            'product_code' => $latest['product_code'] ?? null,
            'product_name' => $latest['product_name'] ?? null,
            'price_from' => $latest['price_from'] ?? null,
            'price_to' => $latest['price_to'] ?? null,
            'markup_to' => $latest['markup_to'] ?? null,
            'route_id' => $latest['route_id'] ?? null,
            'route_name' => $latest['route_name'] ?? null,
            'updated_at' => $latest['updated_at'] ?? null,
        ]);
    }
}
