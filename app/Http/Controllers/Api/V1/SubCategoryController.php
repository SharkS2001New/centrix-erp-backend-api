<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Category;
use App\Models\Product;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubCategoryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return SubCategory::class;
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['subcategory_name'];
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

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        $productCount = Product::query()
            ->where('organization_id', $model->organization_id)
            ->where('subcategory_id', $model->id)
            ->count();

        if ($productCount > 0) {
            throw ValidationException::withMessages([
                'subcategory' => [
                    "Cannot delete \"{$model->subcategory_name}\" — it is used by {$productCount} product(s). "
                    .'Reassign those products to another sub-category first.',
                ],
            ]);
        }

        return parent::destroy($request, $id);
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
