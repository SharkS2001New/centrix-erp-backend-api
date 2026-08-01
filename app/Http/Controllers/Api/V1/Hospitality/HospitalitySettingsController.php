<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Models\HospitalityRecipe;
use App\Models\Organization;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityPosSettings;
use App\Services\Hospitality\HospitalityRecipeService;
use Illuminate\Http\Request;

class HospitalitySettingsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityRecipeService $recipes,
    ) {}

    public function show(Request $request)
    {
        $org = $this->org($request);
        $gate = $this->erp->gateForOrganization($org);
        $settings = HospitalityPosSettings::forOrganization($org);

        return response()->json([
            'hospitality' => $settings,
            'setup_guide' => $this->setupGuide($org, $gate, $settings),
        ]);
    }

    public function update(Request $request)
    {
        $org = $this->org($request);
        $data = $request->validate([
            'stock_deduct_on_settle' => 'sometimes|boolean',
            'stock_location' => 'sometimes|in:shop,store',
            'block_settle_if_insufficient' => 'sometimes|boolean',
            'require_recipe_for_stocked_items' => 'sometimes|boolean',
        ]);

        $current = $org->module_settings ?? [];
        if (! is_array($current)) {
            $current = [];
        }
        $hospitality = is_array($current['hospitality'] ?? null) ? $current['hospitality'] : [];

        foreach ($data as $key => $value) {
            $hospitality[$key] = $value;
        }

        $org->putModuleSettingsSection('hospitality', $hospitality);
        $fresh = $org->fresh();
        $gate = $this->erp->gateForOrganization($fresh);
        $settings = HospitalityPosSettings::forOrganization($fresh);

        return response()->json([
            'hospitality' => $settings,
            'setup_guide' => $this->setupGuide($fresh, $gate, $settings),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{steps: list<array<string, mixed>>, ready: bool}
     */
    protected function setupGuide(Organization $org, $gate, array $settings): array
    {
        $inventoryOn = $gate->enabled('inventory');
        $recipeCount = HospitalityRecipe::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->count();
        $deductOn = (bool) $settings['stock_deduct_on_settle'];

        $steps = [
            [
                'id' => 'inventory_module',
                'title' => 'Enable Inventory',
                'done' => $inventoryOn,
                'description' => 'Hospitality stock uses the shared Inventory module for on-hand balances (unga, oil, bottles).',
                'action_href' => '/admin/settings',
                'action_label' => 'Organization settings',
            ],
            [
                'id' => 'receive_ingredients',
                'title' => 'Receive ingredients into stock',
                'done' => $inventoryOn,
                'description' => 'Buy/receive raw items (e.g. 1 bale unga) with the correct UOM so stock is in kg or pieces.',
                'action_href' => '/inventory/receipts',
                'action_label' => 'Stock receipts',
            ],
            [
                'id' => 'configure_recipes',
                'title' => 'Configure menu recipes',
                'done' => $recipeCount > 0,
                'description' => 'Cooked meals use Recipe mode (ingredients). Packaged drinks use Direct deduct of the bottle/can itself.',
                'action_href' => '#recipes',
                'action_label' => 'Recipes below',
            ],
            [
                'id' => 'enable_deduct',
                'title' => 'Turn on deduct on settle',
                'done' => $deductOn,
                'description' => 'When a Hotel POS check is paid, Centrix deducts ingredients (or the packaged item) from stock.',
                'action_href' => '#stock-balancing',
                'action_label' => 'Stock balancing',
            ],
        ];

        $ready = $inventoryOn && $recipeCount > 0 && $deductOn;

        return [
            'steps' => $steps,
            'ready' => $ready,
            'recipe_count' => $recipeCount,
            'inventory_enabled' => $inventoryOn,
        ];
    }

    protected function org(Request $request): Organization
    {
        return $this->erp->resolveOrganization($request);
    }
}
