<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Category;
use App\Models\SubCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CategoryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Category::class;
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        $subCategoryCount = SubCategory::query()
            ->where('organization_id', $model->organization_id)
            ->where('category_id', $model->id)
            ->count();

        if ($subCategoryCount > 0) {
            throw ValidationException::withMessages([
                'category' => [
                    "Cannot delete \"{$model->category_name}\" — it has {$subCategoryCount} sub-categor"
                    .($subCategoryCount === 1 ? 'y' : 'ies')
                    .'. Delete or reassign those sub-categories first.',
                ],
            ]);
        }

        return parent::destroy($request, $id);
    }
}
