<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\BackofficeOrderLineEditService;
use App\Services\Sales\CentrixSalesScope;
use App\Services\Sales\PosOrderEditService;
use App\Services\Sales\RouteOrderScope;
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
            app(\App\Services\Sales\SaleRouteBackfillService::class)->syncOrganization($gate->organization());
            $distributionSettings = $gate->distributionSettings();
            RouteOrderScope::applyForLoadingList(
                $query,
                RouteOrderScope::includeNormalOrders($distributionSettings),
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
        $statusFilter = data_get($request->input('filter', []), 'status');
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $channel = (string) ($request->input('channel') ?: 'backend');
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
        } elseif ($request->filled('order_source')) {
            $query->where('sales.order_source', $request->input('order_source'));
        }

        if ($request->filled('channel')) {
            $query->where('sales.channel', $request->input('channel'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate(DB::raw('COALESCE(sales.completed_at, sales.created_at)'), '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate(DB::raw('COALESCE(sales.completed_at, sales.created_at)'), '<=', $request->input('to_date'));
        }

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
            $assignStatus = (string) (($distributionSettings ?? [])['assign_on_status'] ?? 'processed');
            $query->where('sales.status', $assignStatus);
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

        $paginator = $query->orderByDesc('sales.id')->paginate($perPage);
        $editService = app(PosOrderEditService::class);
        $lineEditService = app(BackofficeOrderLineEditService::class);
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
        $channel = $sale->channel ?: 'backend';
        $workflow = OrderWorkflowService::forGate($gate)->forChannel($channel);
        $editService = app(PosOrderEditService::class);
        $lineEditService = app(BackofficeOrderLineEditService::class);

        return response()->json(array_merge($sale->toArray(), [
            'workflow' => $workflow,
            'workflow_status' => OrderWorkflowService::forGate($gate)->alignStatusToPipeline(
                (string) $sale->status,
                $channel,
            ),
            'can_edit' => $editService->canRestoreSaleToCart($sale, $request->user(), $gate),
            'can_edit_lines' => $lineEditService->canEditLineQuantities($sale, $request->user(), $gate),
            'order_connectivity' => $sale->mobileOrderConnectivity(),
            'is_offline_order' => $sale->isOfflineMobileOrder(),
        ]));
    }
}
