<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;

class SubCategoryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SubCategory::class;
    }

    public function store(Request $request)
    {
        $this->assertCategoryInOrganization($request);

        return parent::store($request);
    }

    public function update(Request $request, string $id)
    {
        $this->assertCategoryInOrganization($request);

        return parent::update($request, $id);
    }

    protected function assertCategoryInOrganization(Request $request): void
    {
        $categoryId = (int) $request->input('category_id');
        if ($categoryId <= 0) {
            return;
        }

        $orgId = $this->access()->organizationId($request->user(), $request);
        if (! $orgId) {
            return;
        }

        $exists = Category::query()
            ->where('id', $categoryId)
            ->where('organization_id', $orgId)
            ->exists();

        abort_unless($exists, 422, 'Category does not belong to this organization.');
    }
}
