<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserMobileOrderScopeService;
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\Cache\OrganizationCache;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Support\SqlLikeSearch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class MobileSalesService
{
    public function __construct(
        protected UserAccessService $access,
        protected UserMobileOrderScopeService $mobileScope,
        protected ErpContext $erp,
        protected PosOrderEditService $posOrderEdit,
    ) {}

    /**
     * @return array{
     *     summary: array<string, int|float>,
     *     recent_orders: list<array<string, mixed>>,
     *     weekly_sales: list<array<string, mixed>>,
     *     monthly_sales: list<array<string, mixed>>
     * }
     */
    public function dashboard(User $user, ?Carbon $from = null, ?Carbon $to = null, bool $allChannels = false): array
    {
        $to ??= now()->startOfDay();
        $from ??= $to->copy();

        if ($allChannels && ! $this->mobileScope->canUseAllChannels($user)) {
            $allChannels = false;
        }

        $orgId = (int) ($user->organization_id ?? 0);
        $ttl = (int) config('cache.mobile_dashboard_ttl', 60);
        $cacheable = $orgId > 0 && $ttl > 0 && $from->isSameDay($to);

        $build = fn (): array => $this->buildDashboardPayload($user, $from, $to, $allChannels);

        if (! $cacheable) {
            return $build();
        }

        $key = sprintf(
            'mobile-dashboard:u%d:%s:%d',
            (int) $user->id,
            $from->toDateString(),
            $allChannels ? 1 : 0,
        );

        return OrganizationCache::remember($orgId, $key, $ttl, $build);
    }

    /** Drop cached same-day dashboard payloads for a rep after checkout or order changes. */
    public function invalidateDashboardForUser(User $user, ?Carbon $date = null): void
    {
        $orgId = (int) ($user->organization_id ?? 0);
        if ($orgId <= 0) {
            return;
        }

        $date ??= now()->startOfDay();
        $dateKey = $date->toDateString();

        foreach ([0, 1] as $allChannelsFlag) {
            OrganizationCache::forget(
                $orgId,
                sprintf(
                    'mobile-dashboard:u%d:%s:%d',
                    (int) $user->id,
                    $dateKey,
                    $allChannelsFlag,
                ),
            );
        }
    }

    /**
     * @return array{
     *     summary: array<string, int|float>,
     *     recent_orders: list<array<string, mixed>>,
     *     weekly_sales: list<array<string, mixed>>,
     *     monthly_sales: list<array<string, mixed>>
     * }
     */
    protected function buildDashboardPayload(User $user, Carbon $from, Carbon $to, bool $allChannels): array
    {
        $salesQuery = $this->mobileSalesQuery($user, $allChannels)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->where('status', '!=', 'cancelled');

        $summaryRow = (clone $salesQuery)
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(ROUND(SUM(total_vat), 2), 0) as vat_total')
            ->selectRaw('COALESCE(ROUND(SUM(order_total), 2), 0) as order_total')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN payment_status = ? OR status IN (?, ?, ?) THEN 1 ELSE 0 END), 0) as paid_count',
                ['paid', 'paid', 'completed', 'delivered'],
            )
            ->first();

        $customerCount = Customer::query()
            ->whereNull('deleted_at')
            ->when(
                $user->organization_id,
                fn (Builder $q) => $q->where('organization_id', $user->organization_id),
            );
        $this->access->scopeBranchIfLimited($customerCount, $user);
        $this->mobileScope->applyCustomerScope($customerCount, $user);

        return [
            'mobile_context' => $this->mobileScope->mobileContext($user),
            'summary' => array_merge([
                'NoofOrders' => (int) ($summaryRow->order_count ?? 0),
                'vatTotals' => (float) ($summaryRow->vat_total ?? 0),
                'orderTotals' => (float) ($summaryRow->order_total ?? 0),
                'noofPaidOrders' => (int) ($summaryRow->paid_count ?? 0),
                'noofCustomers' => (int) $customerCount->count(),
            ], $this->workflowQueueCounts($user, $allChannels)),
            'recent_orders' => $this->recentOrders($user, $from, $to, $allChannels),
            'weekly_sales' => $this->trendSales($user, $to->copy()->subDays(6), $to, $allChannels),
            'monthly_sales' => $this->trendSales($user, $to->copy()->subDays(29), $to, $allChannels),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listOrders(User $user, array $filters = []): array
    {
        $cache = app(CompletedSalesCacheService::class);
        $cachedList = $cache->getMobileListFromCache($user, $filters);
        if ($cachedList !== null) {
            $gate = $this->erp->gateForUser($user);
            $saleIds = collect($cachedList['data'] ?? [])->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $salesById = $saleIds === []
                ? collect()
                : Sale::query()->whereIn('id', $saleIds)->get()->keyBy('id');

            $cachedList['data'] = collect($cachedList['data'] ?? [])
                ->map(function (array $row) use ($user, $gate, $salesById) {
                    $sale = $salesById->get((int) ($row['id'] ?? 0));
                    if (! $sale) {
                        return $row;
                    }

                    return array_merge($row, [
                        'can_edit' => $this->posOrderEdit->canRestoreSaleToCart($sale, $user, $gate),
                        ...$this->cancellationCapabilities($sale, $user),
                    ]);
                })
                ->values()
                ->all();

            return $cachedList;
        }

        $from = isset($filters['from_date'])
            ? Carbon::parse($filters['from_date'])->startOfDay()
            : now()->startOfDay();
        $to = isset($filters['to_date'])
            ? Carbon::parse($filters['to_date'])->startOfDay()
            : now()->startOfDay();

        $allChannels = filter_var($filters['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($allChannels && ! $this->mobileScope->canUseAllChannels($user)) {
            $allChannels = false;
        }

        $query = $this->mobileSalesQuery($user, $allChannels)
            ->with('customer');

        $workflowStatus = in_array((string) ($filters['status'] ?? ''), ['pending_approval', 'editable'], true)
            ? (string) $filters['status']
            : null;

        if ($workflowStatus) {
            $query->where('status', $workflowStatus);
        } else {
            $query
                ->whereDate('created_at', '>=', $from->toDateString())
                ->whereDate('created_at', '<=', $to->toDateString())
                ->where('status', '!=', 'cancelled');
        }

        $query->orderByDesc('id');

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            SqlLikeSearch::applySalesOrderSearch($query, $q, includeCustomerRelation: true);
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);
        $page = $query->paginate($perPage);
        $gate = $this->erp->gateForUser($user);

        $result = [
            'data' => collect($page->items())
                ->map(fn (Sale $sale) => array_merge(
                    $this->presentOrderSummary($sale),
                    [
                        'can_edit' => $this->posOrderEdit->canRestoreSaleToCart($sale, $user, $gate),
                        ...$this->cancellationCapabilities($sale, $user),
                    ],
                ))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];

        if (
            $cache->canServeMobileListFromCache($filters)
            && $cache->isPastDate(Carbon::parse((string) $filters['from_date'])->toDateString())
        ) {
            $orgId = (int) ($user->organization_id ?? 0);
            if ($orgId > 0) {
                $date = Carbon::parse((string) $filters['from_date'])->toDateString();
                $allChannels = filter_var($filters['all_channels'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $cache->putMobileDayList($orgId, (int) $user->id, $date, $allChannels, [
                    'data' => collect($result['data'])
                        ->map(fn (array $row) => collect($row)->except(['can_edit', 'can_cancel', 'can_direct_cancel', 'can_request_cancellation'])->all())
                        ->values()
                        ->all(),
                    'meta' => $result['meta'],
                ]);
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function showOrder(User $user, int $saleId, bool $allChannels = false): array
    {
        if ($allChannels && ! $this->mobileScope->canUseAllChannels($user)) {
            $allChannels = false;
        }

        $sale = $this->mobileSalesQuery($user, $allChannels)
            ->with(['items.product', 'cashier'])
            ->findOrFail($saleId);

        $cache = app(CompletedSalesCacheService::class);
        if ($cache->isImmutableSale($sale)) {
            $orgId = (int) ($user->organization_id ?? 0);
            $cached = $orgId > 0 ? $cache->getSaleDetail($orgId, $saleId, 'mobile') : null;
            if (is_array($cached)) {
                return $cache->hydrateMobileOrderDetail($cached, $sale, $user);
            }
        }

        $detail = array_merge(
            $this->presentOrderSummary($sale),
            [
                'can_edit' => $this->canRestoreSaleToCart($sale, $user),
                ...$this->cancellationCapabilities($sale, $user),
                'items' => $this->mapOrderItems($sale),
            ],
        );

        if ($cache->isImmutableSale($sale)) {
            $orgId = (int) ($user->organization_id ?? 0);
            if ($orgId > 0) {
                $cache->putSaleDetail($orgId, $saleId, 'mobile', collect($detail)->except([
                    'can_edit', 'can_cancel', 'can_direct_cancel', 'can_request_cancellation',
                ])->all());
            }
        }

        return $detail;
    }

    /** @return list<array<string, mixed>> */
    public function mapOrderItems(Sale $sale): array
    {
        return $sale->items->map(function ($item) {
            $product = $item->product;
            $isRetail = (bool) $item->on_wholesale_retail;
            $display = app(SaleLineQuantityDisplayService::class);
            $qtyDisp = $product
                ? $display->formatLineQtyDisplay(
                    (float) $item->quantity,
                    $product,
                    $isRetail,
                    $item->uom,
                )
                : trim((float) $item->quantity.' '.($item->uom ?? ''));
            $discountGiven = round((float) ($item->discount_given ?? 0), 2);
            $displayUnitPrice = $product
                ? $display->displayUnitPrice(
                    (float) $item->quantity,
                    (float) $item->amount,
                    $product,
                    $isRetail,
                    $discountGiven,
                    (float) $item->selling_price,
                )
                : (float) $item->selling_price;
            $displayAmount = $product
                ? $display->displayLineAmount(
                    (float) $item->quantity,
                    (float) $item->amount,
                    $product,
                    $isRetail,
                    $discountGiven,
                    (float) $item->selling_price,
                )
                : (float) $item->amount;

            return [
                'sale_item_id' => (int) $item->id,
                'product_code' => $item->product_code,
                'product_name' => $product?->product_name ?? $item->product_code,
                'qty' => (float) $item->quantity,
                'qtyDisp' => $qtyDisp,
                'uom' => $item->uom,
                'unit_price' => $displayUnitPrice,
                'unit_price_per_base' => (float) $item->selling_price,
                'discount_given' => $discountGiven,
                'product_vat' => (float) $item->product_vat,
                'amount' => $displayAmount,
                'sell_on_retail' => (int) $item->on_wholesale_retail,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function buildCachedOrderSummary(Sale $sale): array
    {
        return $this->presentOrderSummary($sale);
    }

    /** @return array<string, mixed> */
    public function buildCachedOrderDetail(Sale $sale): array
    {
        return array_merge(
            $this->presentOrderSummary($sale),
            ['items' => $this->mapOrderItems($sale)],
        );
    }

    /** @return list<array<string, mixed>> */
    protected function recentOrders(User $user, Carbon $from, Carbon $to, bool $allChannels = false): array
    {
        return $this->mobileSalesQuery($user, $allChannels)
            ->with(['customer', 'cashier'])
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->where('status', '!=', 'cancelled')
            ->whereNotIn('status', ['pending_approval', 'editable'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Sale $sale) => array_merge(
                $this->presentOrderSummary($sale),
                ['can_edit' => $this->canRestoreSaleToCart($sale, $user)],
            ))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function trendSales(User $user, Carbon $from, Carbon $to, bool $allChannels = false): array
    {
        $rows = $this->mobileSalesQuery($user, $allChannels)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DATE(created_at) as sale_day')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(ROUND(SUM(order_total), 2), 0) as total_amount')
            ->groupBy('sale_day')
            ->orderBy('sale_day')
            ->get();

        return $rows->map(function ($row) {
            $day = Carbon::parse($row->sale_day);

            return [
                'create_date' => $day->format('j-n'),
                'total_amount' => (float) $row->total_amount,
                'order_count' => (int) $row->order_count,
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    protected function dailyTrendWithCounts(
        User $user,
        Carbon $from,
        Carbon $to,
        bool $allChannels = false,
    ): array {
        $rows = $this->mobileSalesQuery($user, $allChannels)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->where('status', '!=', 'cancelled')
            ->selectRaw('DATE(created_at) as sale_day')
            ->selectRaw('COUNT(*) as order_count')
            ->selectRaw('COALESCE(ROUND(SUM(order_total), 2), 0) as total_amount')
            ->groupBy('sale_day')
            ->orderBy('sale_day')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->sale_day)->toDateString());

        $result = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $row = $rows->get($key);
            $result[] = [
                'create_date' => $key,
                'label' => $day->format('j M'),
                'order_count' => (int) ($row->order_count ?? 0),
                'total_amount' => (float) ($row->total_amount ?? 0),
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    protected function weeklyBucketsInMonth(
        User $user,
        Carbon $monthStart,
        Carbon $to,
        bool $allChannels = false,
    ): array {
        $daily = $this->dailyTrendWithCounts($user, $monthStart, $to, $allChannels);

        return $this->weeklyBucketsFromDaily($daily, $monthStart, $to);
    }

    /**
     * @param  list<array<string, mixed>>  $daily
     * @return list<array<string, mixed>>
     */
    protected function weeklyBucketsFromDaily(array $daily, Carbon $monthStart, Carbon $to): array
    {
        $dailyByDate = collect($daily)->keyBy('create_date');
        $buckets = [];
        $cursor = $monthStart->copy();

        while ($cursor->lte($to)) {
            $bucketEnd = $cursor->copy()->addDays(6);
            if ($bucketEnd->gt($to)) {
                $bucketEnd = $to->copy();
            }

            $orderCount = 0;
            $totalAmount = 0.0;
            for ($day = $cursor->copy(); $day->lte($bucketEnd); $day->addDay()) {
                $row = $dailyByDate->get($day->toDateString());
                if ($row) {
                    $orderCount += (int) ($row['order_count'] ?? 0);
                    $totalAmount += (float) ($row['total_amount'] ?? 0);
                }
            }

            $buckets[] = [
                'create_date' => $cursor->toDateString(),
                'label' => $cursor->format('j').'-'.$bucketEnd->format('j M'),
                'order_count' => $orderCount,
                'total_amount' => round($totalAmount, 2),
            ];

            $cursor = $bucketEnd->copy()->addDay();
        }

        return $buckets;
    }

    /** @return Builder<Sale> */
    protected function mobileSalesQuery(User $user, bool $allChannels = false): Builder
    {
        $query = Sale::query()
            ->where('archived', 0)
            ->whereNull('deleted_at')
            ->where('cashier_id', $user->id);

        CentrixSalesScope::excludeLegacyMaterialized($query, 'sales');

        if (! $allChannels) {
            $query->where('channel', 'mobile');
        }

        $this->mobileScope->applySaleScope($query, $user);

        return $query;
    }

    /** @return array{pending_approval_count: int, editable_count: int} */
    protected function workflowQueueCounts(User $user, bool $allChannels = false): array
    {
        $base = $this->mobileSalesQuery($user, $allChannels);

        return [
            'pending_approval_count' => (int) (clone $base)->where('status', 'pending_approval')->count(),
            'editable_count' => (int) (clone $base)->where('status', 'editable')->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function presentOrderSummary(Sale $sale): array
    {
        $sale->loadMissing(['customer', 'cashier']);
        $labels = config('erp.order_status_labels', []);

        return [
            'id' => $sale->id,
            'order_no' => (int) $sale->order_num,
            'customer_num' => $sale->customer_num ? (int) $sale->customer_num : null,
            'customer_name' => $sale->customer?->customer_name
                ?? $sale->customer_name_override
                ?? 'Walk-in',
            'orderTotals' => round((float) $sale->order_total, 2),
            'order_discount' => round((float) ($sale->order_discount ?? 0), 2),
            'total_discount' => app(SaleOrderPresentationService::class)->totalDiscount($sale),
            'status' => $sale->status,
            'status_name' => $labels[$sale->status] ?? ucfirst(str_replace('_', ' ', (string) $sale->status)),
            'payment_status' => $sale->payment_status,
            'createdBy' => $sale->cashier?->username ?? '',
            'channel' => $sale->channel,
            'created_at' => $sale->created_at,
            'route_markup_applied' => (bool) (($sale->fulfillment_meta ?? [])['route_markup']['applied'] ?? false),
            'route_markup_message' => ($sale->fulfillment_meta ?? [])['route_markup']['message'] ?? null,
            'order_connectivity' => $sale->mobileOrderConnectivity(),
            'is_offline_order' => $sale->isOfflineMobileOrder(),
            'discount_rejected' => (bool) (app(SaleOrderPresentationService::class)->discountRejectionPresentation($sale)),
            'discount_rejection' => app(SaleOrderPresentationService::class)->discountRejectionPresentation($sale),
        ];
    }

    public function canRestoreSaleToCart(Sale $sale, User $user): bool
    {
        if ($this->posOrderEdit->blocksPreviousDayMobileMutation($sale)) {
            return false;
        }

        return $this->posOrderEdit->canRestoreSaleToCart(
            $sale,
            $user,
            $this->erp->gateForUser($user),
        );
    }

    /**
     * @param  list<array{id: int, quantity: float|int|string, discount_given?: float|int|string|null}>  $items
     * @return array<string, mixed>
     */
    public function updateEditableOrderLines(
        User $user,
        int $saleId,
        array $items,
        bool $allChannels = false,
    ): array {
        if ($allChannels && ! $this->mobileScope->canUseAllChannels($user)) {
            $allChannels = false;
        }

        $sale = $this->mobileSalesQuery($user, $allChannels)->findOrFail($saleId);

        if ((string) $sale->status !== 'editable') {
            throw ValidationException::withMessages([
                'status' => 'Only orders returned for discount revision can be edited here.',
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $updated = app(BackofficeOrderLineEditService::class)->updateLineQuantities(
            $sale,
            $user,
            $items,
            $gate,
        );

        return $this->showOrder($user, (int) $updated->id, $allChannels);
    }

    /** @return array{can_cancel: bool, can_direct_cancel: bool, can_request_cancellation: bool} */
    public function cancellationCapabilities(Sale $sale, User $user): array
    {
        if (! $this->isSaleCancellableByWorkflow($sale, $user)) {
            return [
                'can_cancel' => false,
                'can_direct_cancel' => false,
                'can_request_cancellation' => false,
            ];
        }

        $gate = $this->erp->gateForUser($user);
        $cancellations = app(SaleCancellationService::class);

        if (! $cancellations->cancellationApprovalEnabled($gate)) {
            return [
                'can_cancel' => true,
                'can_direct_cancel' => true,
                'can_request_cancellation' => false,
            ];
        }

        $canDirect = app(OrderCancellationRequestService::class)->canDirectCancel($user);

        return [
            'can_cancel' => true,
            'can_direct_cancel' => $canDirect,
            'can_request_cancellation' => ! $canDirect,
        ];
    }

    public function canCancelSale(Sale $sale, User $user): bool
    {
        return $this->cancellationCapabilities($sale, $user)['can_cancel'];
    }

    protected function isSaleCancellableByWorkflow(Sale $sale, User $user): bool
    {
        if ($this->posOrderEdit->blocksPreviousDayMobileMutation($sale)) {
            return false;
        }

        $gate = $this->erp->gateForUser($user);

        return OrderWorkflowService::forGate($gate)->isCancellableStatus(
            (string) $sale->status,
            $sale->channel,
        );
    }

    /**
     * @return array{
     *     month_label: string,
     *     from_date: string,
     *     to_date: string,
     *     daily_sales: list<array<string, mixed>>,
     *     weekly_sales: list<array<string, mixed>>
     * }
     */
    public function reconciliation(User $user, bool $allChannels = false): array
    {
        if ($allChannels && ! $this->mobileScope->canUseAllChannels($user)) {
            $allChannels = false;
        }

        $monthStart = now()->startOfMonth();
        $today = now()->startOfDay();
        $dailySales = $this->dailyTrendWithCounts($user, $monthStart, $today, $allChannels);

        return [
            'month_label' => $monthStart->format('F Y'),
            'from_date' => $monthStart->toDateString(),
            'to_date' => $today->toDateString(),
            'daily_sales' => $dailySales,
            'weekly_sales' => $this->weeklyBucketsFromDaily($dailySales, $monthStart, $today),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function createOrderReturn(
        User $user,
        int $saleId,
        array $data,
        bool $allChannels = false,
    ): CustomerReturn {
        $sale = $this->mobileSalesQuery($user, $allChannels)
            ->with(['items.product'])
            ->findOrFail($saleId);

        if ($sale->status === 'cancelled' || (int) ($sale->archived ?? 0) === 1) {
            throw ValidationException::withMessages([
                'sale_id' => 'Cannot return items from a cancelled order.',
            ]);
        }

        if (! $sale->created_at?->isSameDay(now())) {
            throw ValidationException::withMessages([
                'sale_id' => 'Returns are only allowed for orders placed today.',
            ]);
        }

        $lines = $data['lines'] ?? null;
        if (! empty($data['full_order']) || empty($lines)) {
            $lines = $sale->items->map(static fn ($item) => [
                'product_code' => $item->product_code,
                'return_qty' => (float) $item->quantity,
                'quantity_sold' => (float) $item->quantity,
                'unit_price' => (float) $item->selling_price,
                'amount' => (float) $item->amount,
                'sale_item_id' => (int) $item->id,
                'product_name' => $item->product?->product_name ?? $item->product_code,
                'uom' => $item->uom,
            ])->all();
        }

        if ($lines === [] || $lines === null) {
            throw ValidationException::withMessages([
                'lines' => 'No items available to return for this order.',
            ]);
        }

        return app(CustomerReturnService::class)->create($user, [
            'sale_id' => $sale->id,
            'customer_num' => $sale->customer_num,
            'branch_id' => $sale->branch_id,
            'reason' => $data['reason'] ?? null,
            'stock_location' => $data['stock_location'] ?? null,
            'auto_approve' => true,
            'lines' => $lines,
        ]);
    }
}
