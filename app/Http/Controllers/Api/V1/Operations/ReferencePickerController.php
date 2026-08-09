<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\RouteModel;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Services\Auth\UserAccessService;
use App\Support\SalesReportUserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Org-scoped reference lists for form/report pickers.
 * Authenticated tenant users only — not gated by admin or catalogue view permissions.
 */
class ReferencePickerController extends Controller
{
    protected function organizationId(Request $request): int
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        abort_unless($orgId, 403);

        return (int) $orgId;
    }

    protected function perPage(Request $request, int $default = 200, int $max = 500): int
    {
        return min(max((int) $request->input('per_page', $default), 1), $max);
    }

    protected function applyLikeSearch(Builder $query, string $q, array $columns): void
    {
        $q = trim($q);
        if ($q === '' || $columns === []) {
            return;
        }

        $query->where(function ($inner) use ($columns, $q) {
            foreach ($columns as $index => $col) {
                if ($index === 0) {
                    $inner->where($col, 'like', "%{$q}%");
                } else {
                    $inner->orWhere($col, 'like', "%{$q}%");
                }
            }
        });
    }

    /** @param  Builder<User>  $query */
    protected function scopeUsersForPicker(Request $request, Builder $query): Builder
    {
        $access = app(UserAccessService::class);
        $user = $request->user();

        if ($access->isOrgWide($user)) {
            return $query;
        }

        $branchId = $access->branchId($user);
        if ($branchId === null) {
            return $query;
        }

        return $query->where(function ($inner) use ($branchId) {
            $inner->where('branch_id', $branchId)
                ->orWhere('access_scope', 'org');
        });
    }

    public function users(Request $request)
    {
        $orgId = $this->organizationId($request);

        $query = User::query()
            ->whereNull('deleted_at')
            ->where('organization_id', $orgId)
            ->orderBy('full_name');

        $query = $this->scopeUsersForPicker($request, $query);

        // Sales data / report seller pickers: POS, backoffice sales create, mobile sales.
        if ($request->boolean('sales_capable') || $request->input('for') === 'sales') {
            SalesReportUserScope::applyEligibleSalesReportUsers($query);
        }

        $this->applyLikeSearch($query, (string) $request->input('q', ''), ['full_name', 'username', 'email']);

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function vats(Request $request)
    {
        $orgId = $this->organizationId($request);

        $query = Vat::query()
            ->where('organization_id', $orgId)
            ->orderBy('vat_name');
        $this->applyLikeSearch($query, (string) $request->input('q', ''), ['vat_name', 'vat_code']);

        return response()->json($query->paginate($this->perPage($request, 50, 200)));
    }

    public function categories(Request $request)
    {
        $orgId = $this->organizationId($request);

        $query = Category::query()
            ->where('organization_id', $orgId)
            ->orderBy('category_name');
        $this->applyLikeSearch($query, (string) $request->input('q', ''), ['category_name']);

        return response()->json(
            $query->paginate($this->perPage($request), ['id', 'category_name', 'organization_id']),
        );
    }

    public function subCategories(Request $request)
    {
        $orgId = $this->organizationId($request);

        $query = SubCategory::query()
            ->where('organization_id', $orgId)
            ->orderBy('subcategory_name');
        $this->applyLikeSearch($query, (string) $request->input('q', ''), ['subcategory_name']);

        return response()->json(
            $query->paginate(
                $this->perPage($request, 500, 500),
                ['id', 'category_id', 'subcategory_name', 'organization_id'],
            ),
        );
    }

    public function uoms(Request $request)
    {
        $orgId = $this->organizationId($request);

        $query = Uom::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->orderBy('full_name')
            ->orderBy('measure_name');
        $this->applyLikeSearch($query, (string) $request->input('q', ''), ['full_name', 'measure_name']);

        return response()->json($query->paginate($this->perPage($request, 500, 500)));
    }

    public function suppliers(Request $request)
    {
        $orgId = $this->organizationId($request);

        $query = Supplier::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->orderBy('supplier_name');

        if ($request->filled('is_active') || $request->filled('filter.is_active')) {
            $active = $request->has('is_active')
                ? $request->boolean('is_active')
                : $request->boolean('filter.is_active');
            $query->where('is_active', $active);
        }

        $this->applyLikeSearch(
            $query,
            (string) $request->input('q', ''),
            ['supplier_name', 'supplier_code', 'contact_person', 'phone', 'email'],
        );

        return response()->json(
            $query->paginate(
                $this->perPage($request),
                ['id', 'supplier_code', 'supplier_name', 'is_active', 'organization_id'],
            ),
        );
    }

    public function routes(Request $request)
    {
        $orgId = $this->organizationId($request);
        $access = app(UserAccessService::class);
        $user = $request->user();

        $query = RouteModel::query()
            ->where('organization_id', $orgId)
            ->orderBy('route_name');

        if (! $access->isOrgWide($user)) {
            $access->scopeBranchIfLimitedOrShared($query, $user);
        }

        if ($request->filled('is_active') || $request->filled('filter.is_active')) {
            $active = $request->has('is_active')
                ? $request->boolean('is_active')
                : $request->boolean('filter.is_active');
            $query->where('is_active', $active);
        } else {
            $query->where('is_active', true);
        }

        $this->applyLikeSearch($query, (string) $request->input('q', ''), ['route_name', 'direction']);

        return response()->json(
            $query->paginate(
                $this->perPage($request, 50, 200),
                ['id', 'route_name', 'direction', 'is_active', 'branch_id', 'organization_id'],
            ),
        );
    }

    public function paymentMethods(Request $request)
    {
        $orgId = $this->organizationId($request);

        $query = PaymentMethod::query()
            ->where('organization_id', $orgId)
            ->orderBy('method_name');

        if ($request->filled('is_active') || $request->filled('filter.is_active')) {
            $active = $request->has('is_active')
                ? $request->boolean('is_active')
                : $request->boolean('filter.is_active');
            $query->where('is_active', $active);
        } else {
            $query->where('is_active', true);
        }

        $this->applyLikeSearch($query, (string) $request->input('q', ''), ['method_name', 'method_code']);

        return response()->json(
            $query->paginate(
                $this->perPage($request, 50, 200),
                ['id', 'method_name', 'method_code', 'is_active', 'organization_id'],
            ),
        );
    }
}
