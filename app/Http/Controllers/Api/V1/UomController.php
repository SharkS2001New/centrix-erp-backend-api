<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Product;
use App\Models\Uom;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UomController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return Uom::class;
    }

    public function destroy(Request $request, string $id)
    {
        $model = $this->findScopedModel($request, $id);

        $productCount = Product::query()
            ->where('organization_id', $model->organization_id)
            ->where('unit_id', $model->id)
            ->count();

        if ($productCount > 0) {
            throw ValidationException::withMessages([
                'uom' => [
                    "Cannot delete \"{$model->full_name}\" — it is used by {$productCount} product(s). "
                    .'Reassign those products to another unit first.',
                ],
            ]);
        }

        return parent::destroy($request, $id);
    }
}
