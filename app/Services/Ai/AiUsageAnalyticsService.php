<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiUsageAnalyticsService
{
    public function __construct(protected AiUsageCostEstimator $estimator) {}

    /**
     * @param  array{from?: ?string, to?: ?string, organization_id?: ?int, user_id?: ?int, provider?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function platformSummary(array $filters = []): array
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            return [
                'available' => false,
                'message' => 'AI usage logging is not installed yet.',
            ];
        }

        [$fromDate, $toDate] = $this->period($filters['from'] ?? null, $filters['to'] ?? null);
        $base = $this->filteredQuery($fromDate, $toDate, $filters);
        $okSql = $this->successSql();
        $hasCost = Schema::hasColumn('ai_usage_logs', 'estimated_cost');
        $hasLatency = Schema::hasColumn('ai_usage_logs', 'latency_ms');
        $costSelect = $hasCost ? 'COALESCE(SUM(ai_usage_logs.estimated_cost), 0)' : '0';
        $latencySelect = $hasLatency ? 'AVG(ai_usage_logs.latency_ms)' : 'NULL';

        $totals = (clone $base)
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 0 ELSE 1 END) as error_count")
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->selectRaw("{$costSelect} as estimated_cost")
            ->selectRaw("{$latencySelect} as avg_latency_ms")
            ->first();

        $byDay = (clone $base)
            ->selectRaw('DATE(ai_usage_logs.created_at) as day')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 0 ELSE 1 END) as error_count")
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->selectRaw("{$costSelect} as estimated_cost")
            ->selectRaw("{$latencySelect} as avg_latency_ms")
            ->groupBy(DB::raw('DATE(ai_usage_logs.created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => $this->withFallbackCost($this->metricRow($row, [
                'day' => (string) $row->day,
            ])))
            ->all();

        $byOrganization = (clone $base)
            ->leftJoin('organizations', 'organizations.id', '=', 'ai_usage_logs.organization_id')
            ->selectRaw('ai_usage_logs.organization_id')
            ->selectRaw('organizations.org_name as organization_name')
            ->selectRaw('organizations.company_code as company_code')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 0 ELSE 1 END) as error_count")
            ->selectRaw('COUNT(DISTINCT ai_usage_logs.user_id) as unique_users')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->selectRaw("{$costSelect} as estimated_cost")
            ->selectRaw("{$latencySelect} as avg_latency_ms")
            ->groupBy('ai_usage_logs.organization_id', 'organizations.org_name', 'organizations.company_code')
            ->orderByDesc('requests')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $id = (int) $row->organization_id;

                return $this->withFallbackCost($this->metricRow($row, [
                    'organization_id' => $id,
                    'organization_name' => $row->organization_name ?: ('Org #'.$id),
                    'company_code' => $row->company_code ? (string) $row->company_code : null,
                    'unique_users' => (int) $row->unique_users,
                ]));
            })
            ->all();

        $byUser = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'ai_usage_logs.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'ai_usage_logs.organization_id')
            ->selectRaw('ai_usage_logs.user_id')
            ->selectRaw('ai_usage_logs.organization_id')
            ->selectRaw('users.full_name as user_name')
            ->selectRaw('users.username as username')
            ->selectRaw('users.email as user_email')
            ->selectRaw('organizations.org_name as organization_name')
            ->selectRaw('organizations.company_code as company_code')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 0 ELSE 1 END) as error_count")
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->selectRaw("{$costSelect} as estimated_cost")
            ->selectRaw("{$latencySelect} as avg_latency_ms")
            ->groupBy(
                'ai_usage_logs.user_id',
                'ai_usage_logs.organization_id',
                'users.full_name',
                'users.username',
                'users.email',
                'organizations.org_name',
                'organizations.company_code',
            )
            ->orderByDesc('requests')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $userId = $row->user_id !== null ? (int) $row->user_id : null;
                $orgId = $row->organization_id !== null ? (int) $row->organization_id : null;
                $label = trim((string) ($row->user_name ?: $row->username ?: $row->user_email ?: ''));
                if ($label === '') {
                    $label = $userId ? 'User #'.$userId : 'Unknown user';
                }

                return $this->withFallbackCost($this->metricRow($row, [
                    'user_id' => $userId,
                    'user_name' => $label,
                    'username' => $row->username ? (string) $row->username : null,
                    'user_email' => $row->user_email ? (string) $row->user_email : null,
                    'organization_id' => $orgId,
                    'organization_name' => $row->organization_name ?: ($orgId ? 'Org #'.$orgId : null),
                    'company_code' => $row->company_code ? (string) $row->company_code : null,
                ]));
            })
            ->all();

        $byProvider = (clone $base)
            ->selectRaw('ai_usage_logs.provider')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->selectRaw("{$costSelect} as estimated_cost")
            ->groupBy('ai_usage_logs.provider')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($row) => $this->withFallbackCost($this->metricRow($row, [
                'provider' => (string) ($row->provider ?: 'unknown'),
            ]), (string) ($row->provider ?: 'openai')))
            ->all();

        $byModel = (clone $base)
            ->selectRaw('ai_usage_logs.provider')
            ->selectRaw('ai_usage_logs.model')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN {$okSql} THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw('COALESCE(SUM(ai_usage_logs.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->selectRaw("{$costSelect} as estimated_cost")
            ->selectRaw("{$latencySelect} as avg_latency_ms")
            ->groupBy('ai_usage_logs.provider', 'ai_usage_logs.model')
            ->orderByDesc('requests')
            ->limit(25)
            ->get()
            ->map(fn ($row) => $this->withFallbackCost($this->metricRow($row, [
                'provider' => (string) ($row->provider ?: 'unknown'),
                'model' => (string) ($row->model ?: 'unknown'),
            ]), (string) ($row->provider ?: 'openai'), $row->model ? (string) $row->model : null))
            ->all();

        $byStatus = (clone $base)
            ->selectRaw('ai_usage_logs.status')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(ai_usage_logs.total_tokens), 0) as total_tokens')
            ->groupBy('ai_usage_logs.status')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($row) => [
                'status' => (string) ($row->status ?: 'unknown'),
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
            ])
            ->all();

        $toolCounts = [];
        if (Schema::hasColumn('ai_usage_logs', 'tools_used')) {
            (clone $base)
                ->whereNotNull('ai_usage_logs.tools_used')
                ->orderByDesc('ai_usage_logs.id')
                ->limit(3000)
                ->get(['ai_usage_logs.tools_used'])
                ->each(function (AiUsageLog $log) use (&$toolCounts) {
                    foreach ((array) $log->tools_used as $tool) {
                        $name = is_string($tool) ? $tool : '';
                        if ($name === '') {
                            continue;
                        }
                        $toolCounts[$name] = ($toolCounts[$name] ?? 0) + 1;
                    }
                });
        }
        arsort($toolCounts);
        $topTools = [];
        foreach (array_slice($toolCounts, 0, 15, true) as $name => $count) {
            $topTools[] = ['tool' => $name, 'count' => $count];
        }

        $errorCodes = [];
        if (Schema::hasColumn('ai_usage_logs', 'error_code')) {
            $errorCodes = (clone $base)
                ->whereRaw("NOT ({$okSql})")
                ->whereNotNull('ai_usage_logs.error_code')
                ->selectRaw('ai_usage_logs.error_code')
                ->selectRaw('COUNT(*) as requests')
                ->groupBy('ai_usage_logs.error_code')
                ->orderByDesc('requests')
                ->limit(10)
                ->get()
                ->map(fn ($row) => [
                    'error_code' => (string) $row->error_code,
                    'requests' => (int) $row->requests,
                ])
                ->all();
        }

        $activeOrgs = (int) (clone $base)->selectRaw('COUNT(DISTINCT ai_usage_logs.organization_id) as aggregate')->value('aggregate');
        $activeUsers = (int) (clone $base)->selectRaw('COUNT(DISTINCT ai_usage_logs.user_id) as aggregate')->value('aggregate');

        $loggedCost = (float) ($totals->estimated_cost ?? 0);
        $computedCost = 0.0;
        foreach ($byModel as $row) {
            $computedCost += (float) ($row['estimated_cost'] ?? 0);
        }
        $estimatedCost = $loggedCost > 0 ? $loggedCost : round($computedCost, 6);

        return [
            'available' => true,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'cost_currency' => (string) config('ai.pricing.currency', 'USD'),
            'summary' => [
                'requests' => (int) ($totals->requests ?? 0),
                'ok_count' => (int) ($totals->ok_count ?? 0),
                'error_count' => (int) ($totals->error_count ?? 0),
                'input_tokens' => (int) ($totals->input_tokens ?? 0),
                'output_tokens' => (int) ($totals->output_tokens ?? 0),
                'total_tokens' => (int) ($totals->total_tokens ?? 0),
                'estimated_cost' => round($estimatedCost, 6),
                'logged_cost' => round($loggedCost, 6),
                'avg_latency_ms' => $totals->avg_latency_ms !== null ? (int) round((float) $totals->avg_latency_ms) : null,
                'active_organizations' => $activeOrgs,
                'active_users' => $activeUsers,
            ],
            'by_day' => $this->fillDays($byDay, $fromDate, $toDate),
            'by_organization' => $byOrganization,
            'by_user' => $byUser,
            'by_provider' => $byProvider,
            'by_model' => $byModel,
            'by_status' => $byStatus,
            'top_tools' => $topTools,
            'error_codes' => $errorCodes,
            'common_questions' => $this->commonQuestions($fromDate, $toDate, $filters),
            'default_tools' => config('ai.tools', []),
        ];
    }

    /**
     * @param  array{from?: ?string, to?: ?string, organization_id?: ?int, user_id?: ?int, provider?: ?string, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function platformEvents(array $filters = []): array
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            return [
                'available' => false,
                'message' => 'AI usage logging is not installed yet.',
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 0],
            ];
        }

        [$fromDate, $toDate] = $this->period($filters['from'] ?? null, $filters['to'] ?? null);
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $paginator = $this->filteredQuery($fromDate, $toDate, $filters)
            ->with([
                'organization:id,org_name,company_code',
                'user:id,full_name,username,email',
            ])
            ->orderByDesc('ai_usage_logs.id')
            ->paginate($perPage, ['ai_usage_logs.*'], 'page', $page);

        $data = $paginator->getCollection()->map(function (AiUsageLog $log) {
            $org = $log->organization;
            $user = $log->user;
            $userLabel = trim((string) ($user?->full_name ?: $user?->username ?: $user?->email ?: ''));

            return [
                'id' => (int) $log->id,
                'created_at' => optional($log->created_at)?->toIso8601String(),
                'organization_id' => $log->organization_id ? (int) $log->organization_id : null,
                'organization_name' => $org?->org_name ?: ($log->organization_id ? 'Org #'.$log->organization_id : null),
                'company_code' => $org?->company_code,
                'user_id' => $log->user_id ? (int) $log->user_id : null,
                'user_name' => $userLabel !== '' ? $userLabel : ($log->user_id ? 'User #'.$log->user_id : null),
                'username' => $user?->username,
                'provider' => $log->provider,
                'model' => $log->model,
                'status' => $log->status,
                'error_code' => $log->error_code,
                'error_message' => $log->error_message ? Str::limit((string) $log->error_message, 240, '') : null,
                'input_tokens' => (int) $log->input_tokens,
                'output_tokens' => (int) $log->output_tokens,
                'total_tokens' => (int) $log->total_tokens,
                'estimated_cost' => $this->rowCost($log),
                'latency_ms' => $log->latency_ms !== null ? (int) $log->latency_ms : null,
                'tools_used' => array_values(array_filter((array) $log->tools_used, fn ($tool) => is_string($tool) && $tool !== '')),
                'prompt_preview' => $log->prompt_preview ? Str::limit((string) $log->prompt_preview, 180, '') : null,
                'conversation_id' => $log->conversation_id,
            ];
        })->values()->all();

        return [
            'available' => true,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'cost_currency' => (string) config('ai.pricing.currency', 'USD'),
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Cluster logged prompts so platform admins can train from frequent questions.
     *
     * @param  array{from?: ?string, to?: ?string, organization_id?: ?int, user_id?: ?int, provider?: ?string, limit?: int}  $filters
     * @return list<array<string, mixed>>
     */
    public function platformCommonQuestions(array $filters = []): array
    {
        if (! Schema::hasTable('ai_usage_logs') || ! Schema::hasColumn('ai_usage_logs', 'prompt_preview')) {
            return [];
        }

        [$fromDate, $toDate] = $this->period($filters['from'] ?? null, $filters['to'] ?? null);

        return $this->commonQuestions($fromDate, $toDate, $filters, max(5, min(50, (int) ($filters['limit'] ?? 20))));
    }

    /**
     * @param  array{organization_id?: ?int, user_id?: ?int, provider?: ?string}  $filters
     * @return list<array<string, mixed>>
     */
    protected function commonQuestions(Carbon $fromDate, Carbon $toDate, array $filters, int $limit = 20): array
    {
        if (! Schema::hasColumn('ai_usage_logs', 'prompt_preview')) {
            return [];
        }

        $rows = $this->filteredQuery($fromDate, $toDate, $filters)
            ->whereNotNull('ai_usage_logs.prompt_preview')
            ->where('ai_usage_logs.prompt_preview', '!=', '')
            ->orderByDesc('ai_usage_logs.id')
            ->limit(4000)
            ->get(['ai_usage_logs.prompt_preview', 'ai_usage_logs.organization_id', 'ai_usage_logs.status']);

        $clusters = [];
        foreach ($rows as $row) {
            $preview = trim((string) $row->prompt_preview);
            if ($preview === '' || mb_strlen($preview) < 8) {
                continue;
            }
            $key = $this->promptFingerprint($preview);
            if ($key === '' || mb_strlen($key) < 8) {
                continue;
            }
            if (! isset($clusters[$key])) {
                $clusters[$key] = [
                    'question' => Str::limit($preview, 180, ''),
                    'fingerprint' => $key,
                    'count' => 0,
                    'ok_count' => 0,
                    'error_count' => 0,
                    'organization_ids' => [],
                    'examples' => [],
                    'suggested_workspace_id' => $this->suggestWorkspaceId($preview),
                ];
            }
            $clusters[$key]['count']++;
            $status = strtolower((string) ($row->status ?? ''));
            if (in_array($status, ['ok', 'success'], true)) {
                $clusters[$key]['ok_count']++;
            } else {
                $clusters[$key]['error_count']++;
            }
            if ($row->organization_id) {
                $clusters[$key]['organization_ids'][(int) $row->organization_id] = true;
            }
            if (count($clusters[$key]['examples']) < 3 && ! in_array($preview, $clusters[$key]['examples'], true)) {
                $clusters[$key]['examples'][] = Str::limit($preview, 180, '');
            }
        }

        $out = array_values($clusters);
        usort($out, fn ($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['question'], $b['question']));
        $out = array_slice($out, 0, $limit);

        return array_map(function (array $row) {
            $row['organization_count'] = count($row['organization_ids']);
            unset($row['organization_ids']);

            return $row;
        }, $out);
    }

    protected function promptFingerprint(string $text): string
    {
        $normalized = strtolower(trim($text));
        $normalized = str_replace(['-', '_', '/', '\\'], ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}\s?]/u', '', $normalized) ?? $normalized;

        return Str::limit(trim($normalized), 160, '');
    }

    protected function suggestWorkspaceId(string $text): ?string
    {
        $lower = strtolower($text);
        $bestId = null;
        $bestHits = 0;
        foreach (config('ai_workspaces', []) as $id => $def) {
            $hits = 0;
            foreach ($def['keywords'] ?? [] as $keyword) {
                $keyword = strtolower((string) $keyword);
                if ($keyword !== '' && str_contains($lower, $keyword)) {
                    $hits++;
                }
            }
            if ($hits > $bestHits) {
                $bestHits = $hits;
                $bestId = (string) $id;
            }
        }

        return $bestHits > 0 ? $bestId : null;
    }

    /**
     * @param  array{organization_id?: ?int, user_id?: ?int, provider?: ?string}  $filters
     * @return \Illuminate\Database\Eloquent\Builder<AiUsageLog>
     */
    protected function filteredQuery(Carbon $fromDate, Carbon $toDate, array $filters)
    {
        $query = AiUsageLog::query()
            ->whereBetween('ai_usage_logs.created_at', [$fromDate, $toDate]);

        if (! empty($filters['organization_id'])) {
            $query->where('ai_usage_logs.organization_id', (int) $filters['organization_id']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('ai_usage_logs.user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['provider'])) {
            $query->where('ai_usage_logs.provider', (string) $filters['provider']);
        }

        return $query;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function period(?string $from, ?string $to): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(29)->startOfDay();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        return [$fromDate, $toDate];
    }

    protected function successSql(): string
    {
        return "LOWER(COALESCE(ai_usage_logs.status, '')) IN ('ok', 'success')";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function metricRow(object $row, array $extra = []): array
    {
        $avg = $row->avg_latency_ms ?? null;

        return array_merge($extra, [
            'requests' => (int) ($row->requests ?? 0),
            'ok_count' => (int) ($row->ok_count ?? 0),
            'error_count' => (int) ($row->error_count ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'total_tokens' => (int) ($row->total_tokens ?? 0),
            'estimated_cost' => round((float) ($row->estimated_cost ?? 0), 6),
            'avg_latency_ms' => $avg !== null ? (int) round((float) $avg) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function withFallbackCost(array $row, ?string $provider = null, ?string $model = null): array
    {
        if ((float) ($row['estimated_cost'] ?? 0) > 0) {
            return $row;
        }

        $row['estimated_cost'] = $this->estimator->estimate(
            $provider ?: (string) ($row['provider'] ?? 'openai'),
            $model ?? ($row['model'] ?? null),
            (int) ($row['input_tokens'] ?? 0),
            (int) ($row['output_tokens'] ?? 0),
        );

        return $row;
    }

    protected function rowCost(AiUsageLog $log): float
    {
        $logged = (float) ($log->estimated_cost ?? 0);
        if ($logged > 0) {
            return round($logged, 6);
        }

        return $this->estimator->estimate(
            (string) $log->provider,
            $log->model,
            (int) $log->input_tokens,
            (int) $log->output_tokens,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function fillDays(array $rows, Carbon $fromDate, Carbon $toDate): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(string) $row['day']] = $row;
        }

        $out = [];
        for ($day = $fromDate->copy()->startOfDay(); $day->lte($toDate); $day->addDay()) {
            $key = $day->toDateString();
            $out[] = $keyed[$key] ?? [
                'day' => $key,
                'requests' => 0,
                'ok_count' => 0,
                'error_count' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'estimated_cost' => 0.0,
                'avg_latency_ms' => null,
            ];
        }

        return $out;
    }
}
