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
use App\Services\Cache\CompletedSalesCacheService;
use App\Services\OrganizationPlatformConfigService;
use App\Support\EffectiveSaleDate;
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

        $query->with(['cashier:id,username,full_name', 'customer:customer_num,customer_name,route_id']);

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

        if (! $request->boolean('include_archived')) {
            $query->where('sales.archived', 0);
        }

        $gate = $this->erp->gateForUser($request->user());
        $workflow = OrderWorkflowService::forGate($gate);
        $channel = (string) ($request->input('channel') ?: 'backend');
        SalesOrderQueuePermissions::applyIndexScope(
            $query,
            $request->user(),
            $gate,
            app(UserPermissionService::class),
            $channel,
        );
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

        EffectiveSaleDate::applyFromToDateFilter(
            $query,
            $request->filled('from_date') ? (string) $request->input('from_date') : null,
            $request->filled('to_date') ? (string) $request->input('to_date') : null,
        );

        if ($request->filled('min_order_total')) {
            $query->where('sales.order_total', '>=', (float) $request->input('min_order_total'));
        }

        if ($request->filled('max_order_total')) {
            $query->where('sales.order_total', '<=', (float) $request->input('max_order_total'));
        }

        if ($request->filled('required_date')) {
            $date = $request->input('required_date');
            $query->whereDate(
                DB::raw('COALESCE(sales.required_date, sales.delivery_date, DATE(sales.created_at))'),
                $date,
            );
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
            $query->where(function ($sub) use ($q) {
                $sub->where('sales.order_num', 'like', "%{$q}%")
                    ->orWhere('sales.customer_name_override', 'like', "%{$q}%")
                    ->orWhere('sales.customer_num', 'like', "%{$q}%");
            });
        }

        if (! empty($query->getQuery()->joins)) {
            $query->select('sales.*');
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $sort = app(OrganizationPlatformConfigService::class)->normalizeOrdersListSort(
            $request->input('sort')
                ?: ($gate->moduleSettings('sales')['orders_list_sort'] ?? '-created_at'),
        );
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
            $sale->setAttribute(
                'workflow_status',
                $workflow->alignStatusToPipeline((string) $sale->status, $channel),
            );
            $sale->setAttribute(
                'can_edit',
                $editService->canRestoreSaleToCart($sale, $request->user(), $gate),
            );
            $sale->setAttribute(
                'can_edit_lines',
                $lineEditService->canEditLineQuantities($sale, $request->user(), $gate),
            );
            $sale->setAttribute('order_connectivity', $sale->mobileOrderConnectivity());
            $sale->setAttribute('is_offline_order', $sale->isOfflineMobileOrder());

            return $sale;
        });

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        $sale = $this->baseQuery($request)->with(['items.product.unit', 'customer:customer_num,customer_name'])->findOrFail($id);
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
        $workflow = OrderWorkflowService::forGate($gate)->forChannel($channel);
        $editService = app(PosOrderEditService::class);
        $lineEditService = app(BackofficeOrderLineEditService::class);

        $payload = array_merge($sale->toArray(), [
            'workflow' => $workflow,
            'workflow_status' => OrderWorkflowService::forGate($gate)->alignStatusToPipeline(
                (string) $sale->status,
                $channel,
            ),
            'can_edit' => $editService->canRestoreSaleToCart($sale, $request->user(), $gate),
            'can_edit_lines' => $lineEditService->canEditLineQuantities($sale, $request->user(), $gate),
            'order_connectivity' => $sale->mobileOrderConnectivity(),
            'is_offline_order' => $sale->isOfflineMobileOrder(),
        ]);

        if ($cache->isImmutableSale($sale)) {
            $orgId = (int) ($sale->organization_id ?? 0);
            if ($orgId > 0) {
                $cache->putSaleDetail($orgId, (int) $sale->id, 'web', collect($payload)->except([
                    'can_edit', 'can_edit_lines',
                ])->all());
            }
        }

        return response()->json($payload);
    }

    /**
     * Newest-first by order date is the default; matches Sales → Orders / Mobile orders UI.
     *
     * @param  Builder<\App\Models\Sale>  $query
     */
    protected function applyOrdersListSort(Builder $query, string $sort): void
    {
        $orderDate = 'COALESCE(sales.completed_at, sales.created_at)';

        match ($sort) {
            'created_at' => $query
                ->orderByRaw("{$orderDate} asc")
                ->orderBy('sales.id'),
            '-order_num' => $query
                ->orderByDesc('sales.order_num')
                ->orderByDesc('sales.id'),
            'order_num' => $query
                ->orderBy('sales.order_num')
                ->orderBy('sales.id'),
            default => $query
                ->orderByRaw("{$orderDate} desc")
                ->orderByDesc('sales.id'),
        };
    }
}
