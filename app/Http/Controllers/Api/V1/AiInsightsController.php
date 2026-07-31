<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiInsightDeliveryService;
use App\Services\Ai\AiInsightService;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AiInsightsController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected AiInsightService $insights,
        protected AiInsightDeliveryService $delivery,
    ) {}

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $this->assertInsightsAvailable($org);

        return response()->json($this->insights->dashboard($user, $org));
    }

    public function analyzeReport(Request $request)
    {
        $data = $request->validate([
            'report_key' => 'required|string|max:80',
            'filters' => 'sometimes|array',
            'rows' => 'sometimes|array|max:200',
            'summary' => 'sometimes|nullable|array',
            'question' => 'sometimes|nullable|string|max:2000',
        ]);

        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $this->assertInsightsAvailable($org);

        try {
            $result = $this->insights->analyzeReport(
                $user,
                $org,
                $data['report_key'],
                $data['filters'] ?? [],
                $data['rows'] ?? [],
                $data['summary'] ?? null,
                $data['question'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['report_key' => $e->getMessage()]);
        }

        return response()->json($result);
    }

    public function ask(Request $request)
    {
        $data = $request->validate([
            'question' => 'required|string|max:2000',
            'insight_id' => 'sometimes|nullable|string|max:64',
            'context' => 'sometimes|nullable|array',
        ]);

        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $this->assertInsightsAvailable($org);

        try {
            $result = $this->insights->askFollowUp(
                $user,
                $org,
                $data['question'],
                $data['insight_id'] ?? null,
                $data['context'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['question' => $e->getMessage()]);
        }

        return response()->json($result);
    }

    public function stockPulse(Request $request)
    {
        $data = $request->validate([
            'lookback_days' => 'sometimes|integer|min:1|max:90',
        ]);

        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $this->assertInsightsAvailable($org);

        try {
            $result = $this->insights->stockPulse($user, $org, $data['lookback_days'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lookback_days' => $e->getMessage()]);
        }

        return response()->json($result);
    }

    public function salesBrief(Request $request)
    {
        $data = $request->validate([
            'lookback_days' => 'sometimes|integer|min:1|max:90',
        ]);

        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);
        $this->assertInsightsAvailable($org);

        try {
            $result = $this->insights->salesBrief($user, $org, $data['lookback_days'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lookback_days' => $e->getMessage()]);
        }

        return response()->json($result);
    }

    public function deliver(Request $request)
    {
        $data = $request->validate([
            'insight_id' => 'required|string|max:64',
            'recipients' => 'sometimes|array',
            'recipients.emails' => 'sometimes|array',
            'recipients.emails.*' => 'string|max:200',
            'recipients.phones' => 'sometimes|array',
            'recipients.phones.*' => 'string|max:32',
            'recipients.whatsapp_phones' => 'sometimes|array',
            'recipients.whatsapp_phones.*' => 'string|max:32',
        ]);

        $org = $this->erp->resolveOrganization($request);
        $this->assertInsightsAvailable($org);

        $insight = $this->insights->getRun($org, $data['insight_id']);
        if (! $insight) {
            throw ValidationException::withMessages([
                'insight_id' => 'Insight not found or expired. Run Analyze again, then send.',
            ]);
        }

        $result = $this->delivery->deliver($org, $insight, $data['recipients'] ?? null);

        return response()->json([
            'insight_id' => $data['insight_id'],
            ...$result,
        ]);
    }

    protected function assertInsightsAvailable($org): void
    {
        $gate = $this->erp->gateForOrganization($org);
        if (! $gate->aiPlatformEnabled()) {
            abort(404);
        }
        if (! AiSettingsResolver::insightsEnabled($org)) {
            throw ValidationException::withMessages([
                'ai' => 'AI insights are not enabled or not configured for this organization.',
            ]);
        }
    }
}
