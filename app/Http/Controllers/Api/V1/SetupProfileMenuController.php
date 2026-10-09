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
            'label' => 'sometimes|string|max:80',
        ]);

        try {
            $saved = $this->menus->save($profileKey, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'key' => $profileKey,
                'label' => $saved['label'],
                'industry' => $saved['industry'],
                'custom' => $saved['custom'],
                'menu_groups' => $saved['menu_groups'],
                'applications' => $saved['applications'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        if (! Schema::hasTable('platform_setup_profiles')) {
            return response()->json([
                'message' => 'Setup types are not ready yet. Run the latest database migration.',
            ], 503);
        }

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'industry' => 'required|in:commerce,hospitality',
        ]);

        try {
            $row = $this->menus->create($data['name'], $data['industry']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json(['data' => $row], 201);
    }

    public function destroy(string $profileKey)
    {
        try {
            $this->menus->delete($profileKey);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }
}
