<?php

namespace App\Services\Fulfillment;

use App\Models\User;
use InvalidArgumentException;

/**
 * Product-aggregated picking lists for mobile route orders when Distribution is disabled.
 */
class MobilePickingSheetService
{
    public function __construct(
        protected MobileLoadingSheetService $loadingSheets,
        protected LoadingListBuilder $loadingListBuilder,
    ) {}

    public function assertAvailable(bool $distributionEnabled, bool $mobileOrdersEnabled): void
    {
        $this->loadingSheets->assertAvailable($distributionEnabled, $mobileOrdersEnabled);
    }

    /** @return array<int, array<string, mixed>> */
    public function listSheets(User $user, array $filters = []): array
    {
        return $this->loadingSheets->listSheets($user, $filters);
    }

    /** @return array<string, mixed> */
    public function sheetDetail(User $user, int $routeId, string $listDate): array
    {
        if ($routeId <= 0) {
            throw new InvalidArgumentException('Route is required.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
            throw new InvalidArgumentException('Invalid picking date.');
        }

        $loadingDetail = $this->loadingSheets->sheetDetail($user, $routeId, $listDate);
        $loadingList = $loadingDetail['loading_list'] ?? [];
        $saleIds = collect($loadingDetail['orders'] ?? [])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $productLines = $this->loadingListBuilder->aggregateLinesFromSaleIds($saleIds);
        $lines = [];
        foreach ($productLines as $line) {
            $requiredQty = (float) ($line['quantity'] ?? 0);
            $lines[] = [
                'line_no' => (int) ($line['line_no'] ?? 0),
                'product_code' => (string) ($line['product_code'] ?? ''),
                'product_name' => (string) ($line['product_name'] ?? ''),
                'shelf_location' => null,
                'stock_location' => 'store',
                'required_qty' => $requiredQty,
                'picked_qty' => $requiredQty,
                'shortage_qty' => 0.0,
                'quantity_label' => (string) ($line['quantity_label'] ?? ''),
                'pack_breakdown' => (string) ($line['pack_breakdown'] ?? ''),
                'shortage_reason' => null,
            ];
        }

        $dateToken = str_replace('-', '', $listDate);

        return [
            'picking_list' => [
                'list_number' => sprintf('PK-%s-%03d', $dateToken, $routeId),
                'list_date' => $listDate,
                'route_id' => $routeId,
                'route' => $loadingList['route'] ?? null,
                'status' => 'open',
                'order_count' => (int) ($loadingList['order_count'] ?? count($saleIds)),
                'line_count' => count($lines),
                'lines' => $lines,
            ],
            'orders' => $loadingDetail['orders'] ?? [],
        ];
    }
}
