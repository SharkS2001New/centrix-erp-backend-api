<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time backfill when adding organization_id to shared catalog reference tables.
 */
class TenantScopedCatalogReferenceMigrator
{
    public function run(): void
    {
        if (! Schema::hasColumn('uoms', 'organization_id')) {
            return;
        }

        $this->backfillFromProducts('uoms', 'unit_id');
        $this->backfillFromProducts('vats', 'vat_id');
        $this->backfillCategories();
        $this->backfillSubCategoriesFromProducts();
        $this->backfillOrphans('uoms');
        $this->backfillOrphans('vats');
        $this->backfillOrphans('categories');
        $this->backfillOrphans('sub_categories');
        $this->assignFallbackOrganization('uoms');
        $this->assignFallbackOrganization('vats');
        $this->assignFallbackOrganization('categories');
        $this->assignFallbackOrganization('sub_categories');
    }

    protected function backfillFromProducts(string $table, string $productColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasTable('products')) {
            return;
        }

        $rows = DB::table('products')
            ->select($productColumn, DB::raw('MIN(organization_id) AS organization_id'))
            ->whereNotNull($productColumn)
            ->whereNull('deleted_at')
            ->groupBy($productColumn)
            ->get();

        foreach ($rows as $row) {
            $refId = (int) $row->{$productColumn};
            $orgId = (int) $row->organization_id;
            if ($refId <= 0 || $orgId <= 0) {
                continue;
            }

            DB::table($table)
                ->where('id', $refId)
                ->whereNull('organization_id')
                ->update(['organization_id' => $orgId]);
        }

        $shared = DB::table('products')
            ->select($productColumn, DB::raw('COUNT(DISTINCT organization_id) AS org_count'))
            ->whereNotNull($productColumn)
            ->whereNull('deleted_at')
            ->groupBy($productColumn)
            ->having('org_count', '>', 1)
            ->pluck($productColumn);

        foreach ($shared as $refId) {
            $this->duplicateReferenceForExtraOrganizations($table, $productColumn, (int) $refId);
        }
    }

    protected function duplicateReferenceForExtraOrganizations(string $table, string $productColumn, int $refId): void
    {
        $original = DB::table($table)->where('id', $refId)->first();
        if ($original === null) {
            return;
        }

        $orgIds = DB::table('products')
            ->where($productColumn, $refId)
            ->whereNull('deleted_at')
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($orgIds->count() <= 1) {
            return;
        }

        $primaryOrg = $orgIds->first();
        DB::table($table)->where('id', $refId)->update(['organization_id' => $primaryOrg]);

        foreach ($orgIds->skip(1) as $orgId) {
            $payload = (array) $original;
            unset($payload['id']);
            $payload['organization_id'] = $orgId;

            if ($table === 'vats') {
                $payload['vat_code'] = $this->uniqueVatCode((string) $payload['vat_code'], $orgId);
            }

            $newId = DB::table($table)->insertGetId($payload);

            DB::table('products')
                ->where($productColumn, $refId)
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->update([$productColumn => $newId]);
        }
    }

    protected function uniqueVatCode(string $code, int $organizationId): string
    {
        $candidate = $code;
        $suffix = 1;

        while (DB::table('vats')
            ->where('organization_id', $organizationId)
            ->where('vat_code', $candidate)
            ->exists()) {
            $suffix++;
            $candidate = substr($code, 0, 16).'-'.$suffix;
        }

        return $candidate;
    }

    protected function backfillCategories(): void
    {
        if (! Schema::hasTable('categories') || ! Schema::hasTable('sub_categories') || ! Schema::hasTable('products')) {
            return;
        }

        $rows = DB::table('products as p')
            ->join('sub_categories as sc', 'sc.id', '=', 'p.subcategory_id')
            ->select('sc.category_id', DB::raw('MIN(p.organization_id) AS organization_id'))
            ->whereNull('p.deleted_at')
            ->groupBy('sc.category_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('categories')
                ->where('id', (int) $row->category_id)
                ->whereNull('organization_id')
                ->update(['organization_id' => (int) $row->organization_id]);
        }

        $shared = DB::table('products as p')
            ->join('sub_categories as sc', 'sc.id', '=', 'p.subcategory_id')
            ->select('sc.category_id', DB::raw('COUNT(DISTINCT p.organization_id) AS org_count'))
            ->whereNull('p.deleted_at')
            ->groupBy('sc.category_id')
            ->having('org_count', '>', 1)
            ->pluck('sc.category_id');

        foreach ($shared as $categoryId) {
            $this->duplicateCategoryTreeForExtraOrganizations((int) $categoryId);
        }
    }

    protected function duplicateCategoryTreeForExtraOrganizations(int $categoryId): void
    {
        $category = DB::table('categories')->where('id', $categoryId)->first();
        if ($category === null) {
            return;
        }

        $orgIds = DB::table('products as p')
            ->join('sub_categories as sc', 'sc.id', '=', 'p.subcategory_id')
            ->where('sc.category_id', $categoryId)
            ->whereNull('p.deleted_at')
            ->distinct()
            ->orderBy('p.organization_id')
            ->pluck('p.organization_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($orgIds->count() <= 1) {
            return;
        }

        $primaryOrg = $orgIds->first();
        DB::table('categories')->where('id', $categoryId)->update(['organization_id' => $primaryOrg]);

        $subcategories = DB::table('sub_categories')->where('category_id', $categoryId)->get();

        foreach ($orgIds->skip(1) as $orgId) {
            $categoryPayload = (array) $category;
            unset($categoryPayload['id']);
            $categoryPayload['organization_id'] = $orgId;
            $newCategoryId = DB::table('categories')->insertGetId($categoryPayload);

            foreach ($subcategories as $subcategory) {
                $subPayload = (array) $subcategory;
                unset($subPayload['id']);
                $subPayload['category_id'] = $newCategoryId;
                $subPayload['organization_id'] = $orgId;
                $newSubId = DB::table('sub_categories')->insertGetId($subPayload);

                DB::table('products')
                    ->where('subcategory_id', (int) $subcategory->id)
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at')
                    ->update(['subcategory_id' => $newSubId]);
            }
        }

        foreach ($subcategories as $subcategory) {
            DB::table('sub_categories')
                ->where('id', (int) $subcategory->id)
                ->whereNull('organization_id')
                ->update(['organization_id' => $primaryOrg]);
        }
    }

    protected function backfillSubCategoriesFromProducts(): void
    {
        if (! Schema::hasTable('sub_categories') || ! Schema::hasTable('products')) {
            return;
        }

        $rows = DB::table('products')
            ->select('subcategory_id', DB::raw('MIN(organization_id) AS organization_id'))
            ->whereNotNull('subcategory_id')
            ->whereNull('deleted_at')
            ->groupBy('subcategory_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('sub_categories')
                ->where('id', (int) $row->subcategory_id)
                ->whereNull('organization_id')
                ->update(['organization_id' => (int) $row->organization_id]);
        }
    }

    protected function backfillOrphans(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'created_by')) {
            return;
        }

        DB::statement(
            "UPDATE {$table} ref
             LEFT JOIN users u ON u.id = ref.created_by
             SET ref.organization_id = u.organization_id
             WHERE ref.organization_id IS NULL AND u.organization_id IS NOT NULL",
        );
    }

    protected function assignFallbackOrganization(string $table): void
    {
        $fallbackOrgId = DB::table('organizations')->orderBy('id')->value('id');
        if (! $fallbackOrgId) {
            return;
        }

        DB::table($table)->whereNull('organization_id')->update([
            'organization_id' => $fallbackOrgId,
        ]);
    }
}
