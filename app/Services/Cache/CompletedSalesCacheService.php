<?php

namespace App\Services\Cache;

use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\MobileSalesService;
use App\Services\Sales\SaleOrderPresentationService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\BackofficeOrderLineEditService;
use App\Services\Sales\PosOrderEditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CompletedSalesCacheService
{
    public function enabled(): bool
    {
        return (bool) config('completed_sales_cache.enabled', true);
    }

    /** @return list<string> */
    public function terminalStatuses(): array
    {
        return array_values(array_unique(array_filter(
            (array) config('completed_sales_cache.terminal_statuses', ['completed', 'delivered', 'paid', 'processed']),
        )));
    }

    public function isTerminalStatus(string $status): bool
    {
        return in_array($status, $this->terminalStatuses(), true);
    }

    public function isImmutableSale(Sale $sale): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ((int) ($sale->archived ?? 0) === 1) {
            return false;
        }

        if (! $this->isTerminalStatus((string) $sale->status)) {
            return false;
        }

        if (! $sale->created_at) {
            return false;
        }

        return $sale->created_at->toDateString() < now()->toDateString();
    }

    public function saleDetailKey(int $saleId, string $format = 'web'): string
    {
        return 'completed-sales:detail:'.$format.':'.$saleId;
    }

    public function mobileDayKey(int $userId, string $date, bool $allChannels): string
    {
        $channel = $allChannels ? 'all' : 'mobile';

        return 'completed-sales:mobile-day:'.$channel.':user:'.$userId.':'.$date;
    }

    /** @return array<string, mixed>|null */
    public function getSaleDetail(int $organizationId, int $saleId, string $format = 'web'): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $hit = OrganizationCache::get($organizationId, $this->saleDetailKey($saleId, $format));

        return is_array($hit) ? $hit : null;
    }

    /** @param array<string, mixed> $payload */
    public function putSaleDetail(int $organizationId, int $saleId, string $format, array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        OrganizationCache::forever($organizationId, $this->saleDetailKey($saleId, $format), $payload);
    }

    public function forgetSale(int $organizationId, int $saleId): void
    {
        OrganizationCache::forget($organizationId, $this->saleDetailKey($saleId, 'web'));
        OrganizationCache::forget($organizationId, $this->saleDetailKey($saleId, 'mobile'));
    }

    public function forgetMobileDay(int $organizationId, int $userId, string $date, bool $allChannels): void
    {
        OrganizationCache::forget($organizationId, $this->mobileDayKey($userId, $date, $allChannels));
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}|null */
    public function getMobileDayList(int $organizationId, int $userId, string $date, bool $allChannels): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $hit = OrganizationCache::get($organizationId, $this->mobileDayKey($userId, $date, $allChannels));

        return is_array($hit) ? $hit : null;
    }

    /** @param array{data: list<array<string, mixed>>, meta: array<string, mixed>} $payload */
    public function putMobileDayList(int $organizationId, int $userId, string $date, bool $allChannels, array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        OrganizationCache::forever($organizationId, $this->mobileDayKey($userId, $date, $allChannels), $payload);
    }

    public function isPastDate(string $date): bool
    {
        return $date < now()->toDateString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function canServeMobileListFromCache(array $filters): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if (filled($filters['q'] ?? null)) {
            return false;
        }

        if (in_array((string) ($filters['status'] ?? ''), ['pending_approval', 'editable'], true)) {
            return false;
        }

        $from = isset($filters['from_date'])
            ? Carbon::parse((string) $filters['from_date'])->toDateString()
            : now()->toDateString();
        $to = isset($filters['to_date'])
            ? Carbon::parse((string) $filters['to_date'])->toDateString()
            : $from;

        return $this->isPastDate($to) && $from === $to;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    public function getMobileListFromCache(User $user, array $filters): ?array
    {
        if (! $this->canServeMobileListFromCache($filters)) {
            return null;
        }

        $date = Carbon::parse((string) $filters['from_date'])->toDateString();
        $allChannels = filter_var($filters['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $orgId = (int) ($user->organization_id ?? 0);
        if ($orgId < 1) {
            return null;
        }

        $cached = $this->getMobileDayList($orgId, (int) $user->id, $date, $allChannels);
        if ($cached === null) {
            return null;
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);
        $rows = $cached['data'] ?? [];
        usort($rows, function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            if ($bTime !== $aTime) {
                return $bTime <=> $aTime;
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        return [
            'data' => array_slice($rows, 0, $perPage),
            'meta' => [
                'current_page' => 1,
                'last_page' => max(1, (int) ceil(max(count($rows), 1) / $perPage)),
                'per_page' => $perPage,
                'total' => count($rows),
                'from_cache' => true,
            ],
        ];
    }

    public function warmOrganization(Organization $organization, ?Carbon $from = null, ?Carbon $to = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $warmDays = max(1, (int) config('completed_sales_cache.warm_days', 365));
        $includeToday = (bool) config('completed_sales_cache.warm_today', true);

        $to ??= $includeToday
            ? now()->startOfDay()
            : now()->subDay()->startOfDay();
        $from ??= $to->copy()->subDays($warmDays - 1);

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $count = 0;
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $count += $this->warmOrganizationDate($organization, $cursor);
            $cursor->addDay();
        }

        return $count;
    }

    public function warmOrganizationDate(Organization $organization, Carbon $date): int
    {
        $dateString = $date->toDateString();
        $orgId = (int) $organization->id;
        $statuses = $this->terminalStatuses();
        $mobileService = app(MobileSalesService::class);

        $sales = Sale::query()
            ->where('organization_id', $orgId)
            ->where('archived', 0)
            ->whereNull('deleted_at')
            ->whereIn('status', $statuses)
            ->whereDate('created_at', $dateString)
            ->with(['items.product', 'customer', 'cashier'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($sales->isEmpty()) {
            return 0;
        }

        $byUserDay = [];

        foreach ($sales as $sale) {
            $this->warmSaleDetail($organization, $sale, $mobileService);

            $cashierId = (int) ($sale->cashier_id ?? 0);
            if ($cashierId < 1) {
                continue;
            }

            $summary = $mobileService->buildCachedOrderSummary($sale);

            if ($sale->channel === 'mobile') {
                $byUserDay[$cashierId]['0'][] = $summary;
            }
            $byUserDay[$cashierId]['1'][] = $summary;
        }

        foreach ($byUserDay as $userId => $channelGroups) {
            foreach (['0' => false, '1' => true] as $flag => $allChannels) {
                $rows = $channelGroups[(string) $flag] ?? [];
                if ($rows === []) {
                    continue;
                }

                usort($rows, function (array $a, array $b): int {
                    $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
                    $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
                    if ($bTime !== $aTime) {
                        return $bTime <=> $aTime;
                    }

                    return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
                });
                $unique = collect($rows)->unique('id')->values()->all();

                $this->putMobileDayList($orgId, (int) $userId, $dateString, $allChannels, [
                    'data' => $unique,
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => count($unique),
                        'total' => count($unique),
                        'from_cache' => true,
                    ],
                ]);
            }
        }

        return $sales->count();
    }

    protected function warmSaleDetail(Organization $organization, Sale $sale, MobileSalesService $mobileService): void
    {
        $orgId = (int) $organization->id;
        $saleId = (int) $sale->id;

        try {
            $gate = app(ErpContext::class)->gateForOrganization($organization);
            $presentation = app(SaleOrderPresentationService::class);
            $enriched = $presentation->enrichSale($sale, null, $gate);
            $channel = $sale->channel ?: 'backend';
            $workflow = OrderWorkflowService::forGate($gate)->forChannel($channel);

            $webPayload = array_merge($enriched->toArray(), [
                'workflow' => $workflow,
                'workflow_status' => OrderWorkflowService::forGate($gate)->alignStatusToPipeline(
                    (string) $sale->status,
                    $channel,
                ),
                'order_connectivity' => $sale->mobileOrderConnectivity(),
                'is_offline_order' => $sale->isOfflineMobileOrder(),
            ]);

            $this->putSaleDetail($orgId, $saleId, 'web', $webPayload);
            $this->putSaleDetail($orgId, $saleId, 'mobile', $mobileService->buildCachedOrderDetail($sale));
        } catch (\Throwable $e) {
            Log::warning('completed_sales_cache.warm_sale_failed', [
                'organization_id' => $orgId,
                'sale_id' => $saleId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>
     */
    public function hydrateMobileOrderDetail(array $cached, Sale $sale, User $user): array
    {
        $mobileService = app(MobileSalesService::class);

        return array_merge($cached, [
            'can_edit' => $mobileService->canRestoreSaleToCart($sale, $user),
            ...$mobileService->cancellationCapabilities($sale, $user),
        ]);
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array<string, mixed>
     */
    public function hydrateWebSaleDetail(array $cached, Sale $sale, User $user): array
    {
        $gate = app(ErpContext::class)->gateForUser($user);
        $editService = app(PosOrderEditService::class);
        $lineEditService = app(BackofficeOrderLineEditService::class);

        return array_merge($cached, [
            'can_edit' => $editService->canRestoreSaleToCart($sale, $user, $gate),
            'can_edit_lines' => $lineEditService->canEditLineQuantities($sale, $user, $gate),
        ]);
    }

    public function invalidateForSale(Sale $sale): void
    {
        $orgId = (int) ($sale->organization_id ?? 0);
        if ($orgId < 1) {
            return;
        }

        $this->forgetSale($orgId, (int) $sale->id);

        if ($sale->created_at && $sale->cashier_id) {
            $date = $sale->created_at->toDateString();
            $this->forgetMobileDay($orgId, (int) $sale->cashier_id, $date, false);
            $this->forgetMobileDay($orgId, (int) $sale->cashier_id, $date, true);
        }
    }
}
