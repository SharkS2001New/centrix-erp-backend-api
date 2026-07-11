<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\OrganizationPlatformConfigService;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WhatsAppSettingsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected OrganizationPlatformConfigService $platformConfig,
    ) {}

    public function show(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        if (! $gate->whatsappPlatformEnabled()) {
            abort(404);
        }

        return response()->json(WhatsAppSettingsResolver::describeForOrganization($org));
    }

    public function update(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $gate = $this->erp->gateForRequest($request);

        if (! $gate->whatsappPlatformEnabled()) {
            abort(404);
        }

        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'agent_name' => 'sometimes|nullable|string|max:80',
            'display_phone' => 'sometimes|nullable|string|max:32',
            'phone_number_id' => 'sometimes|nullable|string|max:64',
            'waba_id' => 'sometimes|nullable|string|max:64',
            'access_token' => 'sometimes|nullable|string|max:500',
            'bot_user_id' => 'sometimes|nullable|integer|exists:users,id',
            'branch_id' => 'sometimes|nullable|integer|exists:branches,id',
            'graph_api_version' => 'sometimes|nullable|string|max:16',
        ]);

        $data = $this->platformConfig->filterOrgManagerWhatsappPayload($data, $gate);

        if ($data === [] && $request->hasAny([
            'enabled', 'agent_name', 'display_phone', 'phone_number_id', 'waba_id', 'access_token', 'bot_user_id', 'branch_id',
        ])) {
            throw ValidationException::withMessages([
                'enabled' => ['WhatsApp ordering is not enabled for this organization by the platform administrator.'],
            ]);
        }

        if (isset($data['bot_user_id'])) {
            $botUser = \App\Models\User::query()->find((int) $data['bot_user_id']);
            if ($botUser && (int) $botUser->organization_id !== (int) $org->id) {
                throw ValidationException::withMessages([
                    'bot_user_id' => ['Bot user must belong to this organization.'],
                ]);
            }
        }

        return response()->json(WhatsAppSettingsResolver::saveOrganization($org, $data));
    }
}
