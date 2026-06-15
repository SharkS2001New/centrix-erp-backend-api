<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;

class AiSettingsController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    public function show(Request $request)
    {
        return response()->json(AiSettingsResolver::describeForClient($request->user()));
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->organization_id);
        $gate = $this->erp->gateForUser($user);

        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'provider' => 'sometimes|in:openai',
            'model' => 'sometimes|nullable|string|max:80',
            'api_key' => 'sometimes|nullable|string|max:250',
            'base_url' => 'sometimes|nullable|string|max:500',
        ]);

        $current = $gate->moduleSettings('ai');
        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['ai'] = AiSettingsResolver::mergeStored($current, $data);
        $org->update(['module_settings' => $moduleSettings]);

        return response()->json(AiSettingsResolver::describeForClient($user->fresh()));
    }
}
