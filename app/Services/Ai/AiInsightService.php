<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiInsightService
{
    public function __construct(
        protected AiInsightDataBuilder $dataBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    public function analyzeReport(
        User $user,
        Organization $organization,
        string $reportKey,
        array $filters = [],
        array $rows = [],
        ?array $summary = null,
        ?string $question = null,
    ): array {
        $slice = $this->dataBuilder->reportSlice($organization, $user, $reportKey, $filters, $rows, $summary);
        $prompt = $question
            ? "Answer this question about the report data: {$question}"
            : 'Analyze this Centrix ERP report. Highlight top findings, risks, and 3 concrete next actions with hrefs from actions_hint when useful.';

        return $this->runInsight($user, $organization, 'report_analyze', $slice, $prompt);
    }

    /** @return array<string, mixed> */
    public function stockPulse(User $user, Organization $organization, ?int $lookbackDays = null): array
    {
        $options = [];
        if ($lookbackDays !== null) {
            $options['lookback_days'] = $lookbackDays;
        }

        return $this->runType($user, $organization, 'stock_pulse', $options);
    }

    /** @return array<string, mixed> */
    public function salesBrief(User $user, Organization $organization, ?int $lookbackDays = null): array
    {
        $options = [];
        if ($lookbackDays !== null) {
            $options['lookback_days'] = $lookbackDays;
        }

        return $this->runType($user, $organization, 'sales_brief', $options);
    }

    /**
     * Run any catalog insight type (digests + on-demand analyses).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runType(User $user, Organization $organization, string $type, array $options = []): array
    {
        $type = str_replace('-', '_', trim($type));
        if (! AiInsightCatalog::isKnown($type)) {
            throw new InvalidArgumentException('Unknown insight type: '.$type);
        }

        $insights = AiSettingsResolver::insightsForOrganization($organization);
        $defaultLookback = (int) (AiInsightCatalog::definitions()[$type]['default_lookback'] ?? 7);
        $days = (array_key_exists('lookback_days', $options) && $options['lookback_days'] !== null)
            ? (int) $options['lookback_days']
            : (int) ($insights[$type]['lookback_days'] ?? $defaultLookback);

        $slice = match ($type) {
            'stock_pulse' => $this->dataBuilder->stockPulseSlice($organization, $user, $days),
            'sales_brief' => $this->dataBuilder->salesBriefSlice($organization, $user, $days),
            'debtors_brief' => $this->dataBuilder->debtorsBriefSlice($organization, $user, $days),
            'cash_till_health' => $this->dataBuilder->cashTillHealthSlice($organization, $user, $days),
            'route_mobile_debrief' => $this->dataBuilder->routeMobileDebriefSlice($organization, $user, $days),
            'exception_radar' => $this->dataBuilder->exceptionRadarSlice($organization, $user, $days),
            'product_demand' => $this->dataBuilder->productDemandSlice(
                $organization,
                $user,
                $days,
                $options['product_code'] ?? null,
                $options['product_query'] ?? $options['q'] ?? null,
            ),
            'customer_360' => $this->dataBuilder->customer360Slice(
                $organization,
                $user,
                (string) ($options['customer_num'] ?? ''),
                $days,
            ),
            'margin_discount_watchdog' => $this->dataBuilder->marginDiscountWatchdogSlice($organization, $user, $days),
            'procurement_companion' => $this->dataBuilder->procurementCompanionSlice($organization, $user, $days),
            'collections_playbook' => $this->dataBuilder->collectionsPlaybookSlice($organization, $user, $days),
            'anomaly_detection' => $this->dataBuilder->anomalyDetectionSlice($organization, $user, $days),
            'forecast_light' => $this->dataBuilder->forecastLightSlice($organization, $user, $days),
            'branch_till_benchmarks' => $this->dataBuilder->branchTillBenchmarksSlice($organization, $user, $days),
            'explain_screen' => $this->dataBuilder->explainScreenSlice(
                $organization,
                $user,
                (string) ($options['screen_key'] ?? 'screen'),
                is_array($options['filters'] ?? null) ? $options['filters'] : [],
                is_array($options['rows'] ?? null) ? $options['rows'] : [],
                is_array($options['summary'] ?? null) ? $options['summary'] : null,
                isset($options['question']) ? (string) $options['question'] : null,
            ),
            default => throw new InvalidArgumentException('Insight type not implemented: '.$type),
        };

        if ($type === 'customer_360' && trim((string) ($options['customer_num'] ?? '')) === '') {
            throw new InvalidArgumentException('customer_num is required for customer_360.');
        }

        $prompt = AiInsightCatalog::prompt($type);
        if (! empty($options['question'])) {
            $prompt .= "\n\nUser question: ".$options['question'];
        }

        return $this->runInsight($user, $organization, $type, $slice, $prompt);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function askFollowUp(
        User $user,
        Organization $organization,
        string $question,
        ?string $insightId = null,
        ?array $context = null,
    ): array {
        $prior = null;
        if ($insightId) {
            $prior = $this->getRun($organization, $insightId);
        }
        $slice = [
            'type' => 'ask',
            'question' => $question,
            'prior_insight' => $prior ? [
                'type' => $prior['type'] ?? null,
                'summary' => $prior['summary'] ?? null,
                'findings' => $prior['findings'] ?? [],
                'data' => $prior['data'] ?? null,
            ] : null,
            'context' => $context,
        ];
        $prompt = "Answer this follow-up about the prior Centrix insight/data: {$question}";

        return $this->runInsight($user, $organization, 'ask', $slice, $prompt, $insightId);
    }

    /** @return array<string, mixed> */
    public function dashboard(User $user, Organization $organization): array
    {
        $cacheKey = 'ai_insights_dashboard:'.$organization->id.':'.($user->branch_id ?? 0);
        return Cache::remember($cacheKey, 120, function () use ($user, $organization) {
            return $this->dataBuilder->dashboardCards($organization, $user);
        });
    }

    /**
     * @param  array<string, mixed>  $slice
     * @return array<string, mixed>
     */
    protected function runInsight(
        User $user,
        Organization $organization,
        string $type,
        array $slice,
        string $userPrompt,
        ?string $parentInsightId = null,
    ): array {
        if (! AiSettingsResolver::insightsEnabled($organization)) {
            throw new InvalidArgumentException(
                'AI insights are not available. Enable AI and Insights under Administration → Settings → AI.',
            );
        }

        $runtime = AiSettingsResolver::resolveRuntimeForOrganization($organization);
        if (! $runtime) {
            throw new InvalidArgumentException('AI is not configured for this organization.');
        }

        $system = <<<'PROMPT'
You are Centrix AI Insights for Centrix ERP (Kenya, KES currency).
Use ONLY the provided JSON data. Do not invent SKUs, amounts, or customers.
Respond with a single JSON object:
{
  "summary": "2-4 sentence executive summary",
  "findings": ["bullet finding", "..."],
  "actions": [{"label": "Open low stock report", "href": "/reports/low-stock"}]
}
Keep findings concrete and actionable. Prefer hrefs from actions_hint in the data when present.
PROMPT;

        try {
            $response = Http::withToken($runtime['api_key'])
                ->timeout(60)
                ->acceptJson()
                ->post($runtime['base_url'].'/chat/completions', [
                    'model' => $runtime['model'],
                    'temperature' => 0.2,
                    'max_tokens' => (int) config('ai.defaults.max_tokens', 1200),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        [
                            'role' => 'user',
                            'content' => $userPrompt."\n\nDATA:\n".json_encode($slice, JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::error('AI insight connection failed', ['message' => $e->getMessage()]);
            throw new InvalidArgumentException('Could not connect to the AI provider.');
        }

        if (! $response->successful()) {
            Log::warning('AI insight API failure', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // One short retry on rate limit (burst / Strict Mode double-open).
            if ($response->status() === 429) {
                usleep(800_000);
                try {
                    $response = Http::withToken($runtime['api_key'])
                        ->timeout(60)
                        ->acceptJson()
                        ->post($runtime['base_url'].'/chat/completions', [
                            'model' => $runtime['model'],
                            'temperature' => 0.2,
                            'max_tokens' => (int) config('ai.defaults.max_tokens', 1200),
                            'response_format' => ['type' => 'json_object'],
                            'messages' => [
                                ['role' => 'system', 'content' => $system],
                                [
                                    'role' => 'user',
                                    'content' => $userPrompt."\n\nDATA:\n".json_encode($slice, JSON_UNESCAPED_UNICODE),
                                ],
                            ],
                        ]);
                } catch (ConnectionException $e) {
                    Log::error('AI insight retry connection failed', ['message' => $e->getMessage()]);
                    throw new InvalidArgumentException('Could not connect to the AI provider.');
                }
            }

            if (! $response->successful()) {
                throw new InvalidArgumentException(
                    $this->formatProviderFailure($response->status(), $response->json('error.message'))
                );
            }
        }

        $content = (string) data_get($response->json(), 'choices.0.message.content', '');
        $parsed = json_decode($content, true);
        if (! is_array($parsed)) {
            $parsed = [
                'summary' => trim($content) !== '' ? trim($content) : 'No insight returned.',
                'findings' => [],
                'actions' => $slice['actions_hint'] ?? [],
            ];
        }

        $insightId = (string) Str::uuid();
        $result = [
            'insight_id' => $insightId,
            'type' => $type,
            'parent_insight_id' => $parentInsightId,
            'summary' => (string) ($parsed['summary'] ?? ''),
            'findings' => array_values(array_filter(array_map('strval', $parsed['findings'] ?? []))),
            'actions' => $this->normalizeActions($parsed['actions'] ?? ($slice['actions_hint'] ?? [])),
            'raw_markdown' => $this->toMarkdown($parsed),
            'data' => $slice,
            'created_at' => now()->toIso8601String(),
        ];

        $this->storeRun($organization, $user, $result);

        return $result;
    }

    protected function formatProviderFailure(int $status, mixed $providerMessage): string
    {
        $detail = trim((string) $providerMessage);
        if (strlen($detail) > 240) {
            $detail = substr($detail, 0, 237).'…';
        }

        return match ($status) {
            401 => 'OpenAI rejected the API key (401). Verify the key under Settings → AI.'
                .($detail ? " Provider: {$detail}" : ''),
            429 => 'OpenAI rate limit or quota exceeded (429). Wait a minute and try again, or check billing on your OpenAI account.'
                .($detail ? " — {$detail}" : ''),
            default => 'AI insight request failed (HTTP '.$status.').'.($detail ? " {$detail}" : ''),
        };
    }

    /** @param  mixed  $actions
     * @return list<array{label: string, href?: string}>
     */
    protected function normalizeActions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }
        $out = [];
        foreach ($actions as $action) {
            if (is_string($action) && trim($action) !== '') {
                $out[] = ['label' => trim($action)];
                continue;
            }
            if (! is_array($action)) {
                continue;
            }
            $label = trim((string) ($action['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $row = ['label' => $label];
            $href = trim((string) ($action['href'] ?? ''));
            if ($href !== '' && str_starts_with($href, '/')) {
                $row['href'] = $href;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $parsed */
    protected function toMarkdown(array $parsed): string
    {
        $lines = [];
        if (! empty($parsed['summary'])) {
            $lines[] = (string) $parsed['summary'];
            $lines[] = '';
        }
        foreach ($parsed['findings'] ?? [] as $finding) {
            $lines[] = '- '.$finding;
        }

        return trim(implode("\n", $lines));
    }

    /** @param  array<string, mixed>  $result */
    protected function storeRun(Organization $organization, User $user, array $result): void
    {
        $key = $this->runCacheKey($organization, (string) $result['insight_id']);
        Cache::put($key, array_merge($result, [
            'organization_id' => $organization->id,
            'created_by' => $user->id,
        ]), now()->addHours(6));
    }

    /** @return array<string, mixed>|null */
    public function getRun(Organization $organization, string $insightId): ?array
    {
        $cached = Cache::get($this->runCacheKey($organization, $insightId));

        return is_array($cached) ? $cached : null;
    }

    protected function runCacheKey(Organization $organization, string $insightId): string
    {
        return 'ai_insight_run:'.$organization->id.':'.$insightId;
    }
}
