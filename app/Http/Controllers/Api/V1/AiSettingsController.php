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
            'insights.debtors_brief' => 'sometimes|array',
            'insights.debtors_brief.enabled' => 'sometimes|boolean',
            'insights.debtors_brief.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.debtors_brief.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.cash_till_health' => 'sometimes|array',
            'insights.cash_till_health.enabled' => 'sometimes|boolean',
            'insights.cash_till_health.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.cash_till_health.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.route_mobile_debrief' => 'sometimes|array',
            'insights.route_mobile_debrief.enabled' => 'sometimes|boolean',
            'insights.route_mobile_debrief.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.route_mobile_debrief.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.exception_radar' => 'sometimes|array',
            'insights.exception_radar.enabled' => 'sometimes|boolean',
            'insights.exception_radar.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.exception_radar.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.margin_discount_watchdog' => 'sometimes|array',
            'insights.margin_discount_watchdog.enabled' => 'sometimes|boolean',
            'insights.margin_discount_watchdog.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.margin_discount_watchdog.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.collections_playbook' => 'sometimes|array',
            'insights.collections_playbook.enabled' => 'sometimes|boolean',
            'insights.collections_playbook.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.collections_playbook.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.anomaly_detection' => 'sometimes|array',
            'insights.anomaly_detection.enabled' => 'sometimes|boolean',
            'insights.anomaly_detection.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.anomaly_detection.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.forecast_light' => 'sometimes|array',
            'insights.forecast_light.enabled' => 'sometimes|boolean',
            'insights.forecast_light.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.forecast_light.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.branch_till_benchmarks' => 'sometimes|array',
            'insights.branch_till_benchmarks.enabled' => 'sometimes|boolean',
            'insights.branch_till_benchmarks.schedule_time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'insights.branch_till_benchmarks.lookback_days' => 'sometimes|integer|min:1|max:90',
            'insights.exception_alerts' => 'sometimes|array',
            'insights.exception_alerts.enabled' => 'sometimes|boolean',
            'insights.exception_alerts.low_stock' => 'sometimes|boolean',
            'insights.exception_alerts.unpaid_spike' => 'sometimes|boolean',
            'insights.exception_alerts.unusual_discounts' => 'sometimes|boolean',
            'insights.exception_alerts.void_cancel_bursts' => 'sometimes|boolean',
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
