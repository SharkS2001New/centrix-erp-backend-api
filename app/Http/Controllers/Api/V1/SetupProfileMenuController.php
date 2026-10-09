<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\SetupProfileMenuService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SetupProfileMenuController extends Controller
{
    public function __construct(protected SetupProfileMenuService $menus) {}

    public function index()
    {
        return response()->json([
            'data' => $this->menus->all(),
        ]);
    }

    public function update(Request $request, string $profileKey)
    {
        if (! in_array($profileKey, $this->menus->profileKeys(), true)) {
            return response()->json(['message' => 'Unknown setup type.'], 404);
        }

        if (! Schema::hasTable('platform_setup_profiles')) {
            return response()->json([
                'message' => 'Setup types are not ready yet. Run the latest database migration.',
            ], 503);
        }

        $data = $request->validate([
            'backoffice' => 'sometimes|array',
            'backoffice.*' => 'sometimes|boolean',
            'hr' => 'sometimes|array',
            'hr.*' => 'sometimes|boolean',
            'accounting' => 'sometimes|array',
            'accounting.*' => 'sometimes|boolean',
            'applications' => 'sometimes|array',
            'applications.*' => 'sometimes|boolean',
        ]);

        $saved = $this->menus->save($profileKey, $data);

        return response()->json([
            'data' => [
                'key' => $profileKey,
                'menu_groups' => $saved['menu_groups'],
                'applications' => $saved['applications'],
            ],
        ]);
    }
}
