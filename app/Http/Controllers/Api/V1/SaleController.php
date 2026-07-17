<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Support\SalesOrderQueuePermissions;
use App\Services\Sales\BackofficeOrderLineEditService;
use App\Services\Sales\CentrixSalesScope;
use App\Services\Sales\PosOrderEditService;
use App\Services\Sales\RouteOrderScope;
use App\Services\Sales\SaleOrderPresentationService;
use App\Services\Sales\SalesListDateScope;
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\OrganizationPlatformConfigService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return Sale::class;
    }

    protected function baseQuery(Request $request)
    {
        $query = Sale::query();
        $user = $request->user();

        if ($user) {
            $this->access()->scopeOrganization($query, $user, 'sales.organization_id', $request);
            if ($this->scopesByBranch()) {
                $this->access()->scopeBranchIfLimited($query, $user, 'sales.branch_id');
            }
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);
        $dispatchOrders = $request->boolean('dispatch_orders');
        $distributionSettings = null;

        if ($request->boolean('with_items')) {
            $query->with(['items.product.unit']);
        }

        $query->with(['cashier:id,username,full_name', 'customer:customer_num,customer_name,route_id,organization_id']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'status') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where("sales.{$col}", $val);
            }
        }

        if ($exclude = $request->input('exclude_status')) {
            $query->where('sales.status', '!=', $exclude);
        }

        if ($request->boolean('route_orders') || $dispatchOrders || $request->boolean('loading_list_orders')) {
            $gate = $this->erp->gateForUser($request->user());
            $distributionSettings = $gate->distributionSettings();
            RouteOrderScope::applyForLoadingList(
                $query,
                RouteOrderScope::includeNormalOrders($distributionSettings),
                RouteOrderScope::includePosRouteOrders($gate->enabled('sales.pos')),
            );
        }

        if (! $request->boolean('include_legacy')) {
            CentrixSalesScope::excludeLegacyMaterialized($query, 'sales');
        }

        $searchQ = trim((string) $request->input('q', ''));
        $isExactOrderLookup = $searchQ !== '' && (
            preg_match('/^#?S0*\d+$/i', $searchQ) === 1
            || ctype_digit($searchQ)
        );

        // Returns / invoice exact lookups must find archived sales (cold storage).
        // List browsing still hides archived unless include_archived is set.
        if (! $request->boolean('include_archived') && ! $isExactOrderLookup) {
            $query->where('sales.archived', 0);
        }

        $gate = $this->erp->gateForUser($request->user());
        $workflow = OrderWorkflowService::forGate($gate);
        $channel = (string) ($request->input('channel') ?: 'backend');
        // Exact order # lookups (returns / invoice load) must see the sale regardless of
        // which sales queue permissions the user has for list browsing.
        if (! $isExactOrderLookup) {
            SalesOrderQueuePermissions::applyIndexScope(
                $query,
                $request->user(),
                $gate,
                app(UserPermissionService::class),
                $channel,
            );
        }
        $statusFilter = data_get($request->input('filter', []), 'status');
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $statuses = $workflow->statusesForQueueFilter((string) $statusFilter, $channel);
            if ($statuses !== []) {
                $query->whereIn('sales.status', $statuses);
            }
        }

        if ($request->input('order_source') === 'backoffice') {
            $query->where(function ($sub) {
                $sub->whereIn('sales.order_source', ['backoffice', 'backend'])
                    ->orWhereIn('sales.channel', ['backoffice', 'backend']);
            });
        } elseif ($request->input('order_source') === 'whatsapp') {
            $query->where(function ($sub) {
                $sub->where('sales.order_source', 'whatsapp')
                    ->orWhere('sales.channel', 'whatsapp');
            });
        } elseif ($request->filled('order_source')) {
            $query->where('sales.order_source', $request->input('order_source'));
        }

        if ($request->filled('channel')) {
            $query->where('sales.channel', $request->input('channel'));
        }

        $dateField = strtolower(trim((string) $request->input('date_field', 'effective')));
        $platformConfig = app(OrganizationPlatformConfigService::class);
        $salesSettings = $gate->moduleSettings('sales');
        $hotWindowDays = $platformConfig->normalizeOrdersListDefaultDays(
            $salesSettings['orders_list_default_days'] ?? 14,
        );
        $searchWindowDays = $platformConfig->normalizeOrdersListSearchDays(
            $salesSettings['orders_list_search_days'] ?? null,
            $hotWindowDays,
        );
        $hasRequiredDateFilter = $request->filled('required_date')
            || $request->filled('required_date_from')
            || $request->filled('required_date_to');

        $deliveryDateExpr = 'COALESCE(sales.required_date, sales.delivery_date, DATE(sales.created_at))';
        $deliveryFrom = null;
        $deliveryTo = null;
        if ($request->filled('required_date')) {
            $deliveryFrom = $deliveryTo = (string) $request->input('required_date');
        } elseif ($hasRequiredDateFilter) {
            $deliveryFrom = $request->filled('required_date_from')
                ? (string) $request->input('required_date_from')
                : (string) $request->input('required_date_to');
            $deliveryTo = $request->filled('required_date_to')
                ? (string) $request->input('required_date_to')
                : (string) $request->input('required_date_from');
            if ($deliveryFrom > $deliveryTo) {
                [$deliveryFrom, $deliveryTo] = [$deliveryTo, $deliveryFrom];
            }
            $maxDays = SalesListDateScope::MAX_RANGE_DAYS;
            $fromCarbon = Carbon::parse($deliveryFrom)->startOfDay();
            $toCarbon = Carbon::parse($deliveryTo)->startOfDay();
            if ($fromCarbon->diffInDays($toCarbon) + 1 > $maxDays) {
                $fromCarbon = $toCarbon->copy()->subDays($maxDays - 1);
                $deliveryFrom = $fromCarbon->toDateString();
            }
        }

        if ($hasRequiredDateFilter && $deliveryFrom !== null && $deliveryTo !== null) {
            // Delivery-date filters are authoritative; still bound created_at so MySQL
            // does not scan the full sales table for the COALESCE expression.
            $createdFrom = Carbon::parse($deliveryFrom)
                ->subDays(SalesListDateScope::MAX_RANGE_DAYS - 1)
                ->toDateString();
            app(SalesListDateScope::class)->applyCreatedAtRange($query, $createdFrom, $deliveryTo);
            $listScope = [
                'from' => $createdFrom,
                'to' => $deliveryTo,
                'applied' => true,
                'skipped_for_search' => false,
                'from_archive' => true,
                'hot_window_days' => $hotWindowDays,
                'search_window' => false,
            ];
        } else {
            $listScope = app(SalesListDateScope::class)->apply(
                $query,
                $request->filled('from_date') ? (string) $request->input('from_date') : null,
                $request->filled('to_date') ? (string) $request->input('to_date') : null,
                $dateField,
                $request->filled('q') ? (string) $request->input('q') : null,
                $hotWindowDays,
                $searchWindowDays,
            );
        }

        if ($request->filled('min_order_total')) {
            $query->where('sales.order_total', '>=', (float) $request->input('min_order_total'));
        }

        if ($request->filled('max_order_total')) {
            $query->where('sales.order_total', '<=', (float) $request->input('max_order_total'));
        }

        if ($deliveryFrom !== null && $deliveryTo !== null) {
            if ($deliveryFrom === $deliveryTo) {
                $query->whereDate(DB::raw($deliveryDateExpr), $deliveryFrom);
            } else {
                $query->whereDate(DB::raw($deliveryDateExpr), '>=', $deliveryFrom)
                    ->whereDate(DB::raw($deliveryDateExpr), '<=', $deliveryTo);
            }
        }

        if ($request->filled('route_id')) {
            RouteOrderScope::applyRouteFilter($query, (int) $request->input('route_id'));
        }

        if ($dispatchOrders) {
            $processedOnly = ($distributionSettings ?? [])['dispatch_board_processed_only'] ?? true;
            if ($processedOnly) {
                $query->where('sales.status', 'processed');
            } else {
                $query->whereNotIn('sales.status', ['cancelled', 'completed', 'delivered', 'expired']);
            }
        } elseif ($request->filled('status_in')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('status_in')))));
            if ($statuses !== []) {
                $query->whereIn('sales.status', $statuses);
            }
        }

        if ($request->filled('exclude_statuses')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('exclude_statuses')))));
            if ($statuses !== []) {
                $query->whereNotIn('sales.status', $statuses);
            }
        }

        if ($request->boolean('outstanding_balance')) {
            $query->whereNotIn('sales.status', ['cancelled', 'expired']);
            $query->whereRaw('(sales.order_total - COALESCE(sales.amount_paid, 0)) > 0.01');
        }

        $paymentStatusFilter = data_get($request->input('filter', []), 'payment_status');
        if (in_array($paymentStatusFilter, ['unpaid', 'partial'], true)) {
            $query->whereNotIn('sales.status', ['completed', 'cancelled', 'expired']);
            $query->whereRaw('(sales.order_total - COALESCE(sales.amount_paid, 0)) > 0.01');
        }

        if ($q = $request->input('q')) {
            $q = trim((string) $q);
            if (preg_match('/^#?S0*(\d+)$/i', $q, $matches)) {
                $query->where('sales.order_num', (int) $matches[1]);
            } elseif (ctype_digit($q)) {
                // Exact order number (e.g. returns lookup for 168 / padded 0168).
                $query->where('sales.order_num', (int) $q);
            } else {
                $query->where(function ($sub) use ($q) {
                    $sub->where('sales.order_num', 'like', "%{$q}%")
                        ->orWhere('sales.customer_name_override', 'like', "%{$q}%")
                        ->orWhere('sales.customer_num', 'like', "%{$q}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
                });
            }
        }

        $this->applyColumnFilters($query, $request);

        if (! empty($query->getQuery()->joins)) {
            $query->select('sales.*');
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $sort = $this->resolveOrdersListSort($request, $gate);
        $this->applyOrdersListSort($query, $sort);

        $paginator = $query->paginate($perPage);
        $editService = app(PosOrderEditService::class);
        $lineEditService = app(BackofficeOrderLineEditService::class);
        $presentation = app(SaleOrderPresentationService::class);
        $paginator->setCollection(
            $presentation->enrichCollection($paginator->getCollection(), $request->user(), $gate)
        );
        $paginator->getCollection()->transform(function (Sale $sale) use ($workflow, $editService, $lineEditService, $request, $gate) {
            $channel = $sale->channel ?: 'backend';
            $status = (string) $sale->status;
            $sale->setAttribute(
                'workflow_status',
                $workflow->alignStatusToPipeline($status, $channel),
            );
            $sale->setAttribute(
                'can_edit',
                $editService->canRestoreSaleToCart($sale, $request->user(), $gate),
            );
            $sale->setAttribute(
                'can_edit_lines',
                $lineEditService->canEditLineQuantities($sale, $request->user(), $gate),
            );
            $sale->setAttribute('can_print_invoice', $workflow->isPrintInvoiceStatus($status, $channel));
            $sale->setAttribute('can_collect_payment', $workflow->isCollectPaymentStatus($status, $channel));
            $sale->setAttribute('order_connectivity', $sale->mobileOrderConnectivity());
            $sale->setAttribute('is_offline_order', $sale->isOfflineMobileOrder());

            return $sale;
        });

        return response()->json(array_merge($paginator->toArray(), [
            'list_scope' => $listScope,
        ]));
    }

    public function show(Request $request, string $id)
    {
        $sale = $this->baseQuery($request)->with(['items.product.unit', 'customer:customer_num,customer_name,organization_id'])->findOrFail($id);
        $gate = $this->erp->gateForUser($request->user());
        $permissions = app(UserPermissionService::class);
        if (! SalesOrderQueuePermissions::userCanViewSale($request->user(), $sale, $gate, $permissions)) {
            abort(403, 'You do not have permission to view this order.');
        }

        $cache = app(CompletedSalesCacheService::class);
        if ($cache->isImmutableSale($sale)) {
            $orgId = (int) ($sale->organization_id ?? 0);
            $cached = $orgId > 0 ? $cache->getSaleDetail($orgId, (int) $sale->id, 'web') : null;
            if (is_array($cached)) {
                return response()->json($cache->hydrateWebSaleDetail($cached, $sale, $request->user()));
            }
        }

        $sale = app(SaleOrderPresentationService::class)->enrichSale($sale, $request->user(), $gate);
        $channel = $sale->channel ?: 'backend';
        $workflowService = OrderWorkflowService::forGate($gate);
        $workflow = $workflowService->forChannel($channel);
        $editService = app(PosOrderEditService::class);
        $lineEditService = app(BackofficeOrderLineEditService::class);
        $status = (string) $sale->status;

        $payload = array_merge($sale->toArray(), [
            'workflow' => $workflow,
            'workflow_status' => $workflowService->alignStatusToPipeline($status, $channel),
            'can_edit' => $editService->canRestoreSaleToCart($sale, $request->user(), $gate),
            'can_edit_lines' => $lineEditService->canEditLineQuantities($sale, $request->user(), $gate),
            'can_print_invoice' => $workflowService->isPrintInvoiceStatus($status, $channel),
            'can_collect_payment' => $workflowService->isCollectPaymentStatus($status, $channel),
            'order_connectivity' => $sale->mobileOrderConnectivity(),
            'is_offline_order' => $sale->isOfflineMobileOrder(),
        ]);

        if ($cache->isImmutableSale($sale)) {
            $orgId = (int) ($sale->organization_id ?? 0);
            if ($orgId > 0) {
                $cache->putSaleDetail($orgId, (int) $sale->id, 'web', collect($payload)->except([
                    'can_edit', 'can_edit_lines', 'can_print_invoice', 'can_collect_payment',
                ])->all());
            }
        }

        return response()->json($payload);
    }

    /**
     * Newest-first by when the order was placed (created_at).
     * Do not sort on completed_at — completed older orders would jump above new ones.
     *
     * @param  Builder<\App\Models\Sale>  $query
     */
    protected function applyOrdersListSort(Builder $query, string $sort): void
    {
        if (in_array($sort, ['customer_name', '-customer_name'], true)) {
            RouteOrderScope::withCustomerRouteJoin($query);
            if (empty($query->getQuery()->columns)) {
                $query->select('sales.*');
            }
            $customerExpr = 'COALESCE('.RouteOrderScope::CUSTOMER_JOIN_ALIAS.'.customer_name, sales.customer_name_override)';
            if ($sort === '-customer_name') {
                $query->orderByRaw("{$customerExpr} desc")->orderByDesc('sales.id');
            } else {
                $query->orderByRaw("{$customerExpr} asc")->orderBy('sales.id');
            }

            return;
        }

        match ($sort) {
            'created_at' => $query
                ->orderBy('sales.created_at')
                ->orderBy('sales.order_num')
                ->orderBy('sales.id'),
            '-order_num' => $query
                ->orderByDesc('sales.order_num')
                ->orderByDesc('sales.id'),
            'order_num' => $query
                ->orderBy('sales.order_num')
                ->orderBy('sales.id'),
            '-order_total' => $query
                ->orderByDesc('sales.order_total')
                ->orderByDesc('sales.id'),
            'order_total' => $query
                ->orderBy('sales.order_total')
                ->orderBy('sales.id'),
            '-status' => $query
                ->orderByDesc('sales.status')
                ->orderByDesc('sales.id'),
            'status' => $query
                ->orderBy('sales.status')
                ->orderBy('sales.id'),
            '-channel' => $query
                ->orderByDesc('sales.channel')
                ->orderByDesc('sales.id'),
            'channel' => $query
                ->orderBy('sales.channel')
                ->orderBy('sales.id'),
            // Default and -created_at: newest placed orders first.
            default => $query
                ->orderByDesc('sales.created_at')
                ->orderByDesc('sales.order_num')
                ->orderByDesc('sales.id'),
        };
    }

    /**
     * @param  Builder<\App\Models\Sale>  $query
     */
    protected function applyColumnFilters(Builder $query, Request $request): void
    {
        $order = trim((string) $request->input('filter_order', ''));
        if ($order !== '') {
            if (preg_match('/^#?S0*(\d+)$/i', $order, $matches)) {
                $query->where('sales.order_num', (int) $matches[1]);
            } else {
                $query->where(function ($sub) use ($order) {
                    $sub->where('sales.order_num', 'like', "%{$order}%")
                        ->orWhereRaw('CAST(sales.order_num AS CHAR) LIKE ?', ["%{$order}%"]);
                });
            }
        }

        $customer = trim((string) $request->input('filter_customer', ''));
        if ($customer !== '') {
            $like = '%'.$customer.'%';
            $query->where(function ($sub) use ($like, $customer) {
                $sub->where('sales.customer_name_override', 'like', $like)
                    ->orWhere('sales.customer_num', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', $like));
                if (ctype_digit($customer)) {
                    $sub->orWhere('sales.customer_num', (int) $customer);
                }
            });
        }

        $amount = trim((string) $request->input('filter_amount', ''));
        if ($amount !== '') {
            $numeric = (float) preg_replace('/[^\d.]/', '', $amount);
            if ($numeric > 0) {
                $query->whereBetween('sales.order_total', [
                    round($numeric - 0.009, 2),
                    round($numeric + 0.009, 2),
                ]);
            }
        }

        $method = trim((string) $request->input('filter_method', ''));
        if ($method !== '') {
            $like = '%'.$method.'%';
            $query->where(function ($sub) use ($like) {
                $sub->where('sales.payment_method_code', 'like', $like)
                    ->orWhere('sales.payment_status', 'like', $like);
            });
        }

        $placedBy = trim((string) $request->input('filter_placed_by', ''));
        if ($placedBy !== '') {
            $like = '%'.$placedBy.'%';
            $query->whereHas('cashier', function ($c) use ($like) {
                $c->where('full_name', 'like', $like)
                    ->orWhere('username', 'like', $like);
            });
        }

        $source = trim((string) $request->input('filter_source', ''));
        if ($source !== '' && strtolower($source) !== 'all') {
            $query->where(function ($sub) use ($source) {
                $sub->where('sales.order_source', $source)
                    ->orWhere('sales.channel', $source);
            });
        }
    }

    /**
     * @param  \App\Services\Erp\CapabilityGate  $gate
     */
    protected function resolveOrdersListSort(Request $request, $gate): string
    {
        $requested = trim((string) $request->input('sort', ''));
        $allowed = [
            '-created_at', 'created_at',
            '-order_num', 'order_num',
            '-order_total', 'order_total',
            '-status', 'status',
            '-channel', 'channel',
            '-customer_name', 'customer_name',
        ];
        if (in_array($requested, $allowed, true)) {
            return $requested;
        }

        return app(OrganizationPlatformConfigService::class)->normalizeOrdersListSort(
            $gate->moduleSettings('sales')['orders_list_sort'] ?? '-created_at',
        );
    }
}
