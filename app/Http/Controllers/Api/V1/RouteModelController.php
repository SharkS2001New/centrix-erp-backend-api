<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\TemporaryCart;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\RouteDashboardStatsService;
use App\Services\Sales\ReceiptPaymentDetailsResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class RouteModelController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return RouteModel::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);
        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }
        if ($q = $request->input('q')) {
            $searchCol = $this->routeKeyColumn() !== 'id'
                ? $this->routeKeyColumn()
                : ($this->fillableFields()[0] ?? 'id');
            $query->where($searchCol, 'like', "%{$q}%");
        }
        $perPage = min((int) $request->input('per_page', 25), 200);
        $this->applyListOrdering($request, $query, 'id', 'desc');

        $paginator = $query->paginate($perPage);

        if ($request->boolean('include_stats')) {
            $gate = $this->erp->gateForUser($request->user());
            $period = (string) $request->input('stats_period', 'day');
            $collection = app(RouteDashboardStatsService::class)->attachStats(
                $paginator->getCollection(),
                $period,
                $gate,
            );
            $paginator->setCollection($collection);
        }

        return response()->json($paginator);
    }

    protected function scopesByOrganization(): bool
    {
        return Schema::hasColumn('routes', 'organization_id');
    }

    protected function routesScopedByOrganization(): bool
    {
        return $this->scopesByOrganization();
    }

    public function store(Request $request)
    {
        $data = $this->validateRoutePayload($request);
        $user = $request->user();
        if ($user && $this->routesScopedByOrganization()) {
            $data['organization_id'] = (int) $user->organization_id;
        }
        $model = RouteModel::create($data);

        if ($request->user() && $this->auditable()) {
            $this->auditLogger()->logModel($request->user(), 'create', $model, request: $request);
        }

        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);
        $data = $this->validateRoutePayload($request, existing: $model);
        $oldValues = $model->getAttributes();
        $model->update($data);
        $model->refresh();

        if ($request->user() && $this->auditable()) {
            $this->auditLogger()->logModel(
                $request->user(),
                'update',
                $model,
                $oldValues,
                $model->getAttributes(),
                $request,
            );
        }

        return response()->json($model);
    }

    /** @return array<string, mixed> */
    protected function validateRoutePayload(Request $request, bool $partial = false, ?RouteModel $existing = null): array
    {
        $prefix = $partial ? 'sometimes' : 'required';
        $orgId = (int) ($request->user()?->organization_id ?? $existing?->organization_id ?? 0);

        $routeNameRules = ["{$prefix}", 'string', 'max:255'];
        if ($orgId > 0 && $this->routesScopedByOrganization()) {
            $routeNameRules[] = Rule::unique('routes', 'route_name')
                ->where(fn ($q) => $q->where('organization_id', $orgId))
                ->ignore($existing?->id);
        } else {
            $routeNameRules[] = Rule::unique('routes', 'route_name')->ignore($existing?->id);
        }

        $rules = array_merge([
            'route_name' => $routeNameRules,
            'direction' => 'nullable|string|max:45',
            'route_markup_price' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ], ReceiptPaymentDetailsResolver::validationRules());

        $data = $request->validate($rules);

        if (array_key_exists('receipt_payment_details', $data)) {
            $data['receipt_payment_details'] = ReceiptPaymentDetailsResolver::normalize(
                is_array($data['receipt_payment_details']) ? $data['receipt_payment_details'] : null,
            );
        }

        return $data;
    }

    public function destroy(Request $request, string $id)
    {
        $route = $this->findScopedModel($request, $id);

        DB::transaction(function () use ($route) {
            Customer::where('route_id', $route->id)->update(['route_id' => null]);
            TemporaryCart::where('route_id', $route->id)->update(['route_id' => null]);
            Sale::where('route_id', $route->id)->update(['route_id' => null]);
            Driver::where('default_route_id', $route->id)->update(['default_route_id' => null]);
            $route->delete();
        });

        return response()->json(null, 204);
    }
}
