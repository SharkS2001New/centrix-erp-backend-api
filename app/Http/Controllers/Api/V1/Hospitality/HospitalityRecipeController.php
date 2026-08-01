<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityRecipeService;
use Illuminate\Http\Request;

class HospitalityRecipeController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityRecipeService $recipes,
    ) {}

    public function index(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);

        return response()->json([
            'data' => $this->recipes->list($org),
        ]);
    }

    public function store(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $data = $this->validated($request);
        $recipe = $this->recipes->upsert($org, $data);

        return response()->json([
            'recipe' => $this->recipes->toArray($recipe),
        ], 201);
    }

    public function update(Request $request, int $recipeId)
    {
        $org = $this->erp->resolveOrganization($request);
        $data = $this->validated($request);
        $recipe = $this->recipes->upsert($org, $data, $recipeId);

        return response()->json([
            'recipe' => $this->recipes->toArray($recipe),
        ]);
    }

    public function destroy(Request $request, int $recipeId)
    {
        $org = $this->erp->resolveOrganization($request);
        $this->recipes->delete($org, $recipeId);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'menu_product_code' => 'required|string|max:64',
            'deduct_mode' => 'sometimes|in:recipe,direct,none',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:255',
            'ingredients' => 'sometimes|array',
            'ingredients.*.ingredient_product_code' => 'required_with:ingredients|string|max:64',
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.0001',
            'ingredients.*.waste_percent' => 'sometimes|numeric|min:0|max:100',
        ]);
    }
}
