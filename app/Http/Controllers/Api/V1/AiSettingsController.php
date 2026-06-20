<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Erp\ErpContext;
use App\Services\OrganizationPlatformConfigService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiSettingsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected OrganizationPlatformConfigService $platformConfig,
    ) {}

    public function show(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        if (! $gate->aiPlatformEnabled()) {
            abort(404);
        }

        return response()->json(AiSettingsResolver::describeForOrganization($org));
    }

    public function update(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'provider' => 'sometimes|in:openai',
            'model' => 'sometimes|nullable|string|max:80',
            'api_key' => 'sometimes|nullable|string|max:250',
            'base_url' => 'sometimes|nullable|string|max:500',
        ]);

        $data = $this->platformConfig->filterOrgManagerAiPayload($data, $gate);

        if (! $gate->aiPlatformEnabled()) {
            abort(404);
        }

        if ($data === [] && $request->hasAny(['enabled', 'provider', 'model', 'api_key', 'base_url'])) {
            throw ValidationException::withMessages([
                'enabled' => ['AI assistant is not enabled for this organization by the platform administrator.'],
            ]);
        }

        $current = $gate->moduleSettings('ai');
        $moduleSettings = $org->module_settings ?? [];
        $moduleSettings['ai'] = AiSettingsResolver::mergeStored($current, $data);
        $org->update(['module_settings' => $moduleSettings]);

        return response()->json(AiSettingsResolver::describeForOrganization($org->fresh()));
    }
}
