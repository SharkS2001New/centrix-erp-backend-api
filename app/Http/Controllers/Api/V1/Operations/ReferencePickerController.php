<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\User;
use App\Models\Vat;
use App\Services\Auth\UserAccessService;
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

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function vats(Request $request)
    {
        $orgId = $this->organizationId($request);

        return response()->json(
            Vat::query()
                ->where('organization_id', $orgId)
                ->orderBy('vat_name')
                ->paginate($this->perPage($request, 50, 200)),
        );
    }

    public function categories(Request $request)
    {
        $orgId = $this->organizationId($request);

        return response()->json(
            Category::query()
                ->where('organization_id', $orgId)
                ->orderBy('category_name')
                ->paginate($this->perPage($request), ['id', 'category_name', 'organization_id']),
        );
    }

    public function subCategories(Request $request)
    {
        $orgId = $this->organizationId($request);

        return response()->json(
            SubCategory::query()
                ->where('organization_id', $orgId)
                ->orderBy('subcategory_name')
                ->paginate(
                    $this->perPage($request, 500, 500),
                    ['id', 'category_id', 'subcategory_name', 'organization_id'],
                ),
        );
    }

    public function uoms(Request $request)
    {
        $orgId = $this->organizationId($request);

        return response()->json(
            Uom::query()
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->orderBy('full_name')
                ->orderBy('measure_name')
                ->paginate($this->perPage($request, 500, 500)),
        );
    }

    public function suppliers(Request $request)
    {
        $orgId = $this->organizationId($request);

        return response()->json(
            Supplier::query()
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->orderBy('supplier_name')
                ->paginate(
                    $this->perPage($request),
                    ['id', 'supplier_code', 'supplier_name', 'is_active', 'organization_id'],
                ),
        );
    }
}
