<?php

namespace App\Services\Fulfillment;

use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Fulfillment\RouteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MobileLoadingSheetService
{
    private const EXCLUDED_STATUSES = ['draft', 'held', 'cancelled', 'expired'];

    public function __construct(
        protected LoadingListBuilder $loadingListBuilder,
        protected UserAccessService $access,
        protected RouteAccessService $routes,
    ) {}

    public function assertAvailable(bool $distributionEnabled, bool $mobileOrdersEnabled): void
    {
        if ($distributionEnabled) {
            throw new InvalidArgumentException(
                'Loading sheets for mobile orders are managed under Distribution dispatch trips.',
            );
        }

        if (! $mobileOrdersEnabled) {
            throw new InvalidArgumentException('Mobile orders are not enabled for this organization.');
        }
    }

    /** @return Builder<Sale> */
    protected function baseMobileSalesQuery(User $user): Builder
    {
        $query = Sale::query()
            ->where('channel', 'mobile')
            ->whereNotNull('route_id')
            ->whereNotIn('status', self::EXCLUDED_STATUSES);

        if ($user->branch_id) {
            $query->where('branch_id', $user->branch_id);
        }

        $this->access->scopeOrganization($query, $user, 'sales.organization_id');

        return $query;
    }

    protected function loadingDateExpression(): string
    {
        return 'COALESCE(DATE(sales.delivery_date), DATE(sales.created_at))';
    }

    /** @return array<int, array<string, mixed>> */
    public function listSheets(User $user, array $filters = []): array
    {
        $dateExpr = $this->loadingDateExpression();
        $query = $this->baseMobileSalesQuery($user)
            ->select([
                'route_id',
                DB::raw("{$dateExpr} as list_date"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(order_total) as order_total'),
            ])
            ->groupBy('route_id', DB::raw($dateExpr));

        if ($routeId = (int) ($filters['route_id'] ?? 0)) {
            $query->where('route_id', $routeId);
        }
        if ($from = $filters['from_date'] ?? null) {
            $query->whereRaw("{$dateExpr} >= ?", [$from]);
        }
        if ($to = $filters['to_date'] ?? null) {
            $query->whereRaw("{$dateExpr} <= ?", [$to]);
        }

        $rows = $query
            ->orderByDesc(DB::raw($dateExpr))
            ->orderBy('route_id')
            ->limit(200)
            ->get();

        $routeNamesQuery = RouteModel::query()
            ->whereIn('id', $rows->pluck('route_id')->filter()->unique()->all());
        $this->routes->scopeOrganization($routeNamesQuery, $user);
        $routeNames = $routeNamesQuery->pluck('route_name', 'id');

        return $rows->map(function ($row) use ($routeNames) {
            return [
                'route_id' => (int) $row->route_id,
                'route_name' => $routeNames[(int) $row->route_id] ?? 'Route #'.$row->route_id,
                'list_date' => (string) $row->list_date,
                'order_count' => (int) $row->order_count,
                'order_total' => round((float) $row->order_total, 2),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function sheetDetail(User $user, int $routeId, string $listDate): array
    {
        if ($routeId <= 0) {
            throw new InvalidArgumentException('Route is required.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
            throw new InvalidArgumentException('Invalid loading date.');
        }

        $dateExpr = $this->loadingDateExpression();
        $sales = $this->baseMobileSalesQuery($user)
            ->where('route_id', $routeId)
            ->whereRaw("{$dateExpr} = ?", [$listDate])
            ->with(['customer'])
            ->orderBy('order_num')
            ->get();

        $route = $this->routes->findForUser($user, $routeId);
        $saleIds = $sales->pluck('id')->all();
        $orders = $this->loadingListBuilder->aggregateOrdersFromSaleIds($saleIds);
        $totalAmount = round(array_sum(array_column($orders, 'subtotal')), 2);

        return [
            'loading_list' => [
                'list_date' => $listDate,
                'route_id' => $routeId,
                'route' => $route ? [
                    'id' => $route->id,
                    'route_name' => $route->route_name,
                ] : null,
                'order_count' => $sales->count(),
                'total_amount' => $totalAmount,
                'orders' => $orders,
            ],
            'orders' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'order_num' => $sale->order_num,
                'status' => $sale->status,
                'order_total' => (float) $sale->order_total,
                'customer_name' => $sale->customer?->customer_name,
                'delivery_date' => $sale->delivery_date,
            ])->values()->all(),
        ];
    }
}
