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
        $insights = AiSettingsResolver::insightsForOrganization($organization);
        $days = $lookbackDays ?? (int) ($insights['stock_pulse']['lookback_days'] ?? 14);
        $slice = $this->dataBuilder->stockPulseSlice($organization, $user, $days);
        $prompt = <<<'PROMPT'
You are Centrix stock intelligence for a Kenyan wholesale/retail business (KES).
Using the JSON data:
1) List fast-moving items (from fast_movers).
2) List items below stock / reorder (from low_stock_items) with suggested reorder focus.
3) Give short, practical purchasing advice.
Return JSON only with keys: summary (string), findings (array of strings), actions (array of {label, href?}).
PROMPT;

        return $this->runInsight($user, $organization, 'stock_pulse', $slice, $prompt);
    }

    /** @return array<string, mixed> */
    public function salesBrief(User $user, Organization $organization, ?int $lookbackDays = null): array
    {
        $insights = AiSettingsResolver::insightsForOrganization($organization);
        $days = $lookbackDays ?? (int) ($insights['sales_brief']['lookback_days'] ?? 7);
        $slice = $this->dataBuilder->salesBriefSlice($organization, $user, $days);
        $prompt = <<<'PROMPT'
You are Centrix sales intelligence for Kenyan field/backoffice sales (KES).
Summarize period performance vs previous period, top products/customers, unpaid risk, and 3 actions managers should take.
Return JSON only with keys: summary (string), findings (array of strings), actions (array of {label, href?}).
PROMPT;

        return $this->runInsight($user, $organization, 'sales_brief', $slice, $prompt);
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
            throw new InvalidArgumentException('AI insight request failed (HTTP '.$response->status().').');
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
