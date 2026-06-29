<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\CentrixSalesScope;
use App\Services\Sales\LegacySalePresentation;
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

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        if ($request->boolean('with_items')) {
            $query->with(['items.product.unit']);
        }

        $query->with(['cashier:id,username,full_name', 'customer:customer_num,customer_name']);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'status') {
                continue;
            }
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($exclude = $request->input('exclude_status')) {
            $query->where('status', '!=', $exclude);
        }

        if ($request->boolean('route_orders')) {
            RouteOrderScope::apply($query);
        }

        if (! $request->boolean('include_legacy')) {
            CentrixSalesScope::excludeLegacyMaterialized($query);
        }

        if (! $request->boolean('include_archived')) {
            $query->where('archived', 0);
        }

        $gate = $this->erp->gateForUser($request->user());
        $workflow = OrderWorkflowService::forGate($gate);
        $statusFilter = data_get($request->input('filter', []), 'status');
        if ($statusFilter !== null && $statusFilter !== '' && $statusFilter !== 'all') {
            $channel = (string) ($request->input('channel') ?: 'backend');
            $statuses = $workflow->statusesForQueueFilter((string) $statusFilter, $channel);
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->input('order_source') === 'backoffice') {
            $query->where(function ($sub) {
                $sub->whereIn('order_source', ['backoffice', 'backend'])
                    ->orWhereIn('channel', ['backoffice', 'backend']);
            });
        } elseif ($request->filled('order_source')) {
            $query->where('order_source', $request->input('order_source'));
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate(DB::raw('COALESCE(completed_at, created_at)'), '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate(DB::raw('COALESCE(completed_at, created_at)'), '<=', $request->input('to_date'));
        }

        if ($request->filled('min_order_total')) {
            $query->where('order_total', '>=', (float) $request->input('min_order_total'));
        }

        if ($request->filled('max_order_total')) {
            $query->where('order_total', '<=', (float) $request->input('max_order_total'));
        }

        if ($request->filled('required_date')) {
            $query->whereDate('required_date', $request->input('required_date'));
        }

        if ($request->filled('route_id')) {
            $query->where('route_id', $request->input('route_id'));
        }

        if ($request->filled('status_in')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('status_in')))));
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->filled('exclude_statuses')) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('exclude_statuses')))));
            if ($statuses !== []) {
                $query->whereNotIn('status', $statuses);
            }
        }

        if ($request->boolean('dispatch_orders') || $request->boolean('loading_list_orders')) {
            $gate = $this->erp->gateForUser($request->user());
            $settings = $gate->distributionSettings();
            RouteOrderScope::applyForLoadingList(
                $query,
                (bool) ($settings['include_normal_orders_in_loading_list'] ?? false),
            );
        }

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('order_num', 'like', "%{$q}%")
                    ->orWhere('customer_name_override', 'like', "%{$q}%")
                    ->orWhere('customer_num', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->getCollection()->transform(function (Sale $sale) use ($workflow) {
            $channel = $sale->channel ?: 'backend';
            $sale->setAttribute(
                'workflow_status',
                $workflow->alignStatusToPipeline((string) $sale->status, $channel),
            );

            return $sale;
        });

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        $sale = $this->baseQuery($request)->with(['items.product.unit', 'customer:customer_num,customer_name'])->findOrFail($id);
        if ($sale->isLegacyImport()) {
            LegacySalePresentation::stripCentrixUnitData($sale);
        }
        $gate = $this->erp->gateForUser($request->user());
        $channel = $sale->channel ?: 'backend';
        $workflow = OrderWorkflowService::forGate($gate)->forChannel($channel);

        return response()->json(array_merge($sale->toArray(), [
            'workflow' => $workflow,
            'workflow_status' => OrderWorkflowService::forGate($gate)->alignStatusToPipeline(
                (string) $sale->status,
                $channel,
            ),
        ]));
    }
}
