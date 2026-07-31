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
            'insights' => 'sometimes|array',
            'insights.enabled' => 'sometimes|boolean',
            'insights.channels' => 'sometimes|array',
            'insights.channels.email' => 'sometimes|boolean',
            'insights.channels.whatsapp' => 'sometimes|boolean',
            'insights.channels.sms' => 'sometimes|boolean',
            'insights.recipients' => 'sometimes|array',
            'insights.recipients.emails' => 'sometimes|array',
            'insights.recipients.emails.*' => 'string|max:200',
            'insights.recipients.phones' => 'sometimes|array',
            'insights.recipients.phones.*' => 'string|max:32',
            'insights.recipients.whatsapp_phones' => 'sometimes|array',
            'insights.recipients.whatsapp_phones.*' => 'string|max:32',
            'insights.stock_pulse' => 'sometimes|array',
            'insights.stock_pulse.enabled' => 'sometimes|boolean',
            'insights.stock_pulse.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.stock_pulse.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.sales_brief' => 'sometimes|array',
            'insights.sales_brief.enabled' => 'sometimes|boolean',
            'insights.sales_brief.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.sales_brief.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.exception_alerts' => 'sometimes|array',
            'insights.exception_alerts.enabled' => 'sometimes|boolean',
            'insights.exception_alerts.low_stock' => 'sometimes|boolean',
            'insights.exception_alerts.unpaid_spike' => 'sometimes|boolean',
        ]);

        $data = $this->platformConfig->filterOrgManagerAiPayload($data, $gate);

        if (! $gate->aiPlatformEnabled()) {
            abort(404);
        }

        if ($data === [] && $request->hasAny(['enabled', 'provider', 'model', 'api_key', 'base_url', 'insights'])) {
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
