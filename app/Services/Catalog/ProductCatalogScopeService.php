<?php

namespace App\Services\Catalog;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductCatalogScopeService
{
    public function __construct(
        protected UserAccessService $access,
    ) {}

    public function headOfficeBranch(int $organizationId): ?Branch
    {
        return Branch::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN branch_code = 'HQ' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    public function branchCount(int $organizationId): int
    {
        return (int) Branch::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->count();
    }

    public function hasMultipleBranches(int $organizationId): bool
    {
        return $this->branchCount($organizationId) > 1;
    }

    /** @return array<string, mixed> */
    public function metadata(int $organizationId): array
    {
        $head = $this->headOfficeBranch($organizationId);
        $count = $this->branchCount($organizationId);

        return [
            'multi_branch' => $count > 1,
            'branch_count' => $count,
            'head_office_branch_id' => $head?->id,
            'head_office_branch_code' => $head?->branch_code,
            'head_office_branch_name' => $head?->branch_name,
            'default_branch_id' => $head?->id,
        ];
    }

    public function catalogScopeForProduct(Product $product): string
    {
        return $product->branch_id === null ? 'organization' : 'branch';
    }

    /** @param  Builder<Product>  $query */
    public function scopeVisibleToBranch(Builder $query, int $organizationId, int $branchId): Builder
    {
        return $query
            ->where('organization_id', $organizationId)
            ->where(function (Builder $inner) use ($branchId) {
                $inner->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
    }

    /** @param  Builder<Product>  $query */
    public function scopeForUser(Builder $query, User $user, ?Request $request = null): Builder
    {
        $orgId = $this->access->organizationId($user, $request);
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $filterBranch = $request?->input('filter.branch_id') ?? $request?->input('branch_id');
        if ($filterBranch !== null && $filterBranch !== '') {
            return $this->scopeVisibleToBranch($query, (int) $orgId, (int) $filterBranch);
        }

        $limitedBranch = $this->access->branchId($user);
        if ($limitedBranch !== null && $orgId) {
            return $this->scopeVisibleToBranch($query, $orgId, $limitedBranch);
        }

        return $query;
    }

    public function assertVisibleAtBranch(Product $product, int $branchId): void
    {
        if ($product->branch_id === null) {
            return;
        }

        if ((int) $product->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'product_code' => ['This product is not available at the selected branch.'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function normalizeWriteData(User $user, array $data, ?Product $existing = null): array
    {
        $orgId = (int) ($data['organization_id'] ?? $existing?->organization_id ?? $user->organization_id ?? 0);
        if ($orgId <= 0) {
            throw ValidationException::withMessages([
                'organization_id' => ['Organization is required.'],
            ]);
        }

        $catalogScope = strtolower((string) ($data['catalog_scope'] ?? ''));
        unset($data['catalog_scope']);

        if (! $this->hasMultipleBranches($orgId)) {
            $data['branch_id'] = null;

            return $data;
        }

        if ($catalogScope === '') {
            $catalogScope = $existing
                ? $this->catalogScopeForProduct($existing)
                : (($data['branch_id'] ?? null) ? 'branch' : 'organization');
        }

        if ($catalogScope === 'organization') {
            if (! $this->access->isOrgWide($user)) {
                throw ValidationException::withMessages([
                    'catalog_scope' => ['Only organization-wide users can create or assign organization-wide products.'],
                ]);
            }
            $data['branch_id'] = null;

            return $data;
        }

        if ($catalogScope !== 'branch') {
            throw ValidationException::withMessages([
                'catalog_scope' => ['Catalog scope must be organization or branch.'],
            ]);
        }

        $branchId = isset($data['branch_id']) && $data['branch_id'] !== ''
            ? (int) $data['branch_id']
            : null;

        if ($branchId === null) {
            $branchId = $this->access->branchId($user) ?? $this->headOfficeBranch($orgId)?->id;
        }

        if ($branchId === null) {
            throw ValidationException::withMessages([
                'branch_id' => ['Branch is required for branch-scoped products.'],
            ]);
        }

        $this->assertBranchInOrganization($orgId, $branchId);

        $limitedBranch = $this->access->branchId($user);
        if ($limitedBranch !== null && $limitedBranch !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['You can only assign products to your branch.'],
            ]);
        }

        $data['branch_id'] = $branchId;

        return $data;
    }

    public function findAccessibleProduct(
        string $productCode,
        int $organizationId,
        int $branchId,
    ): Product {
        $product = Product::query()
            ->where('product_code', $productCode)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $this->assertVisibleAtBranch($product, $branchId);

        return $product;
    }

    protected function assertBranchInOrganization(int $organizationId, int $branchId): void
    {
        $exists = Branch::query()
            ->where('organization_id', $organizationId)
            ->where('id', $branchId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'branch_id' => ['The selected branch does not belong to this organization.'],
            ]);
        }
    }
}
