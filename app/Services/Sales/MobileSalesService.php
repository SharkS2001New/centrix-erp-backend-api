<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\CustomerReturn;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserMobileOrderScopeService;
use App\Services\Erp\ErpContext;
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
            'summary' => [
                'NoofOrders' => (int) ($summaryRow->order_count ?? 0),
                'vatTotals' => (float) ($summaryRow->vat_total ?? 0),
                'orderTotals' => (float) ($summaryRow->order_total ?? 0),
                'noofPaidOrders' => (int) ($summaryRow->paid_count ?? 0),
                'noofCustomers' => (int) $customerCount->count(),
            ],
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
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id');

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $sub) use ($q) {
                $sub->where('order_num', 'like', "%{$q}%")
                    ->orWhere('customer_name_override', 'like', "%{$q}%")
                    ->orWhereHas('customer', function (Builder $customer) use ($q) {
                        $customer->where('customer_name', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);
        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())
                ->map(fn (Sale $sale) => array_merge(
                    $this->presentOrderSummary($sale),
                    ['can_edit' => $this->canRestoreSaleToCart($sale, $user)],
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

        return array_merge(
            $this->presentOrderSummary($sale),
            [
                'can_edit' => $this->canRestoreSaleToCart($sale, $user),
                'can_cancel' => $this->canRestoreSaleToCart($sale, $user),
                'items' => $sale->items->map(function ($item) {
                    $product = $item->product;
                    $isRetail = (bool) $item->on_wholesale_retail;
                    $qtyDisp = $product
                        ? app(SaleLineQuantityDisplayService::class)->formatLineQtyDisplay(
                            (float) $item->quantity,
                            $product,
                            $isRetail,
                            $item->uom,
                        )
                        : trim((float) $item->quantity.' '.($item->uom ?? ''));

                    return [
                        'sale_item_id' => (int) $item->id,
                        'product_code' => $item->product_code,
                        'product_name' => $product?->product_name ?? $item->product_code,
                        'qty' => (float) $item->quantity,
                        'qtyDisp' => $qtyDisp,
                        'uom' => $item->uom,
                        'unit_price' => (float) $item->selling_price,
                        'product_vat' => (float) $item->product_vat,
                        'amount' => (float) $item->amount,
                        'sell_on_retail' => (int) $item->on_wholesale_retail,
                    ];
                })->values()->all(),
            ],
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
        $buckets = [];
        $cursor = $monthStart->copy();

        while ($cursor->lte($to)) {
            $bucketEnd = $cursor->copy()->addDays(6);
            if ($bucketEnd->gt($to)) {
                $bucketEnd = $to->copy();
            }

            $stats = $this->mobileSalesQuery($user, $allChannels)
                ->whereDate('created_at', '>=', $cursor->toDateString())
                ->whereDate('created_at', '<=', $bucketEnd->toDateString())
                ->where('status', '!=', 'cancelled')
                ->selectRaw('COUNT(*) as order_count')
                ->selectRaw('COALESCE(ROUND(SUM(order_total), 2), 0) as total_amount')
                ->first();

            $buckets[] = [
                'create_date' => $cursor->toDateString(),
                'label' => $cursor->format('j').'-'.$bucketEnd->format('j M'),
                'order_count' => (int) ($stats->order_count ?? 0),
                'total_amount' => (float) ($stats->total_amount ?? 0),
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

        if (! $allChannels) {
            $query->where('channel', 'mobile');
        }

        $this->mobileScope->applySaleScope($query, $user);

        if ($user->organization_id) {
            $query->where('organization_id', $user->organization_id);
        }

        $this->access->scopeBranchIfLimited($query, $user);

        return $query;
    }

    /** @return array<string, mixed> */
    protected function presentOrderSummary(Sale $sale): array
    {
        $sale->loadMissing(['customer', 'cashier']);
        $labels = config('erp.order_status_labels', []);

        return [
            'id' => $sale->id,
            'order_no' => (int) $sale->order_num,
            'customer_name' => $sale->customer?->customer_name
                ?? $sale->customer_name_override
                ?? 'Walk-in',
            'orderTotals' => round((float) $sale->order_total, 2),
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
        ];
    }

    public function canRestoreSaleToCart(Sale $sale, User $user): bool
    {
        if (! $sale->created_at?->isSameDay(now())) {
            return false;
        }

        return $this->posOrderEdit->canRestoreSaleToCart(
            $sale,
            $user,
            $this->erp->gateForUser($user),
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

        return [
            'month_label' => $monthStart->format('F Y'),
            'from_date' => $monthStart->toDateString(),
            'to_date' => $today->toDateString(),
            'daily_sales' => $this->dailyTrendWithCounts($user, $monthStart, $today, $allChannels),
            'weekly_sales' => $this->weeklyBucketsInMonth($user, $monthStart, $today, $allChannels),
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
