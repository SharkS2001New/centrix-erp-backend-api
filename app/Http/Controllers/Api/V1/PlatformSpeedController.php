<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Platform\PlatformSpeedService;
use App\Services\Platform\Speed\PlatformSpeedSyntheticRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformSpeedController extends Controller
{
    public function show(Request $request, PlatformSpeedService $speed)
    {
        $range = $this->range($request);

        return response()->json($speed->snapshot($range));
    }

    /**
     * POST /api/v1/admin/platform-speed/probe-stream
     * SSE live probe log for ERP Speed popup.
     */
    public function probeStream(Request $request, PlatformSpeedService $speed): StreamedResponse
    {
        $range = $this->range($request);
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        return response()->stream(function () use ($speed, $range) {
            $send = static function (array $payload): void {
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                @flush();
            };

            try {
                $summary = $speed->liveProbe(function (array $payload) use ($send): void {
                    $send($payload);
                }, $range);

                $send([
                    'event' => 'done',
                    'message' => 'Probe finished',
                    'summary' => $summary,
                ]);
            } catch (\Throwable $e) {
                report($e);
                $send([
                    'event' => 'error',
                    'message' => $e->getMessage() ?: 'Live probe failed',
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * POST /api/v1/admin/platform-speed/advise
     * Centrix AI explanation of a live probe summary.
     */
    public function advise(Request $request, AiProviderFactory $providers)
    {
        $data = $request->validate([
            'summary' => ['required', 'array'],
            'summary.status' => ['nullable', 'string', 'max:32'],
            'summary.latency_probe' => ['nullable', 'array'],
            'summary.api' => ['nullable', 'array'],
            'summary.slow_issues' => ['nullable', 'array'],
            'log_excerpt' => ['nullable', 'string', 'max:12000'],
        ]);

        $heuristic = $this->heuristicSpeedAdvice($data['summary'], (string) ($data['log_excerpt'] ?? ''));
        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();

        if (! $runtime || empty($runtime['api_key'])) {
            return response()->json([
                'advice' => [
                    'source' => 'heuristic',
                    'note' => 'Platform AI credentials are not configured. Showing rule-based advice.',
                    ...$heuristic,
                ],
            ]);
        }

        $system = 'You are Centrix ERP performance assistant for platform super-admins. '
            .'Explain the live ERP Speed probe in plain language. Return JSON only with keys: '
            .'headline (string), findings (string array), actions (string array of concrete next steps), '
            .'urgency (one of ok|watch|urgent). '
            .'Focus on database latency, Redis, slow digests, API p95, and health check failures. '
            .'Never suggest DROP DATABASE or unrestricted DELETE of live sales.';

        $userPrompt = "Probe status: ".($data['summary']['status'] ?? 'unknown')."\n"
            ."Latency: ".json_encode($data['summary']['latency_probe'] ?? [])."\n"
            ."API: ".json_encode($data['summary']['api'] ?? [])."\n"
            ."Slow issues: ".json_encode($data['summary']['slow_issues'] ?? [])."\n"
            ."Log excerpt:\n".substr((string) ($data['log_excerpt'] ?? ''), 0, 8000);

        try {
            $turn = $providers->make($runtime)->chat([
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.2,
                'max_output_tokens' => 900,
            ]);
            $content = trim((string) ($turn['text'] ?? ''));
            $ai = $this->parseSpeedAdvice($content, $heuristic);
        } catch (\Throwable $e) {
            report($e);
            $ai = [
                'source' => 'heuristic',
                'note' => 'Centrix AI call failed; showing rule-based advice. '.$e->getMessage(),
                ...$heuristic,
            ];
        }

        return response()->json(['advice' => $ai]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array{headline: string, findings: list<string>, actions: list<string>, urgency: string, source: string}
     */
    protected function heuristicSpeedAdvice(array $summary, string $logExcerpt): array
    {
        $status = (string) ($summary['status'] ?? 'ok');
        $dbMs = $summary['latency_probe']['db_ms'] ?? null;
        $p95 = $summary['api']['p95'] ?? null;
        $findings = [];
        $actions = [];

        if ($dbMs === null) {
            $findings[] = 'Database probe failed — MySQL may be down or unreachable from this API pod.';
            $actions[] = 'Check database connectivity and Platform → Infrastructure health.';
        } elseif ((int) $dbMs >= 200) {
            $findings[] = "Database SELECT 1 took {$dbMs} ms (slow). Contended locks or overloaded MySQL are likely.";
            $actions[] = 'Open Platform Speed → Database and Data retention; prune audit_logs / agent junk, then OPTIMIZE heavy tables.';
        } else {
            $findings[] = "Database probe is healthy ({$dbMs} ms).";
        }

        if ($p95 !== null && (int) $p95 >= 800) {
            $findings[] = "API p95 is {$p95} ms — users will feel lag on busy endpoints.";
            $actions[] = 'Review Slowest endpoints on Platform Speed → API and inspect the worst SQL digests on Platform Speed → Database.';
        }

        if (($summary['slow_issues']['active'] ?? 0) > 0) {
            $findings[] = 'There are open browser-reported slow API issues.';
            $actions[] = 'Triage System issues (kind=slow) and match them to digests.';
        }

        if ($findings === []) {
            $findings[] = 'No critical latency signals in this probe.';
        }
        if ($actions === []) {
            $actions[] = 'Keep monitoring; re-run the live probe after peak traffic.';
        }

        return [
            'source' => 'heuristic',
            'headline' => match ($status) {
                'critical' => 'ERP Speed is critical — act on failing checks first.',
                'degraded' => 'ERP Speed is degraded — optimize DB / slow digests.',
                default => 'ERP Speed looks OK on this probe.',
            },
            'findings' => $findings,
            'actions' => $actions,
            'urgency' => in_array($status, ['critical', 'degraded'], true) ? ($status === 'critical' ? 'urgent' : 'watch') : 'ok',
        ];
    }

    /**
     * @param  array{headline: string, findings: list<string>, actions: list<string>, urgency: string, source: string}  $fallback
     * @return array<string, mixed>
     */
    protected function parseSpeedAdvice(string $content, array $fallback): array
    {
        if (preg_match('/\{.*\}/s', $content, $m) !== 1) {
            return ['source' => 'ai', 'note' => 'Could not parse AI JSON.', ...$fallback];
        }
        try {
            $json = json_decode($m[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['source' => 'ai', 'note' => 'Could not parse AI JSON.', ...$fallback];
        }
        if (! is_array($json)) {
            return ['source' => 'ai', 'note' => 'Could not parse AI JSON.', ...$fallback];
        }

        return [
            'source' => 'ai',
            'headline' => (string) ($json['headline'] ?? $fallback['headline']),
            'findings' => array_values(array_filter(array_map('strval', $json['findings'] ?? $fallback['findings']))),
            'actions' => array_values(array_filter(array_map('strval', $json['actions'] ?? $fallback['actions']))),
            'urgency' => in_array(($json['urgency'] ?? ''), ['ok', 'watch', 'urgent'], true)
                ? $json['urgency']
                : $fallback['urgency'],
        ];
    }

    public function api(Request $request, PlatformSpeedService $speed)
    {
        return response()->json($speed->apiTab($this->range($request)));
    }

    public function database(Request $request, PlatformSpeedService $speed)
    {
        return response()->json($speed->databaseTab($this->range($request)));
    }

    public function infrastructure(PlatformSpeedService $speed)
    {
        return response()->json($speed->infrastructureTab());
    }

    public function queues(PlatformSpeedService $speed)
    {
        return response()->json($speed->queuesTab());
    }

    public function failedJobs(Request $request)
    {
        if (! Schema::hasTable('failed_jobs')) {
            return response()->json([
                'message' => 'The failed_jobs table is not available on this queue connection.',
            ], 503);
        }

        $page = max(1, (int) $request->query('page', 1));
        $clearAfterDays = $this->failedJobsClearAfterDays();
        $oldCutoff = now()->subDays($clearAfterDays);
        $oldCount = (int) DB::table('failed_jobs')->where('failed_at', '<', $oldCutoff)->count();

        $jobs = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate(25, ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'], 'page', $page);
        $items = $jobs->getCollection()->map(function ($job) use ($oldCutoff) {
            $payload = json_decode((string) $job->payload, true);
            $payload = is_array($payload) ? $payload : [];
            $failedAt = $job->failed_at;

            return [
                'id' => (int) $job->id,
                'uuid' => (string) ($job->uuid ?? ''),
                'connection' => (string) ($job->connection ?? ''),
                'queue' => (string) ($job->queue ?? ''),
                'display_name' => (string) ($payload['displayName'] ?? ''),
                'job_name' => (string) ($payload['data']['commandName'] ?? ''),
                'exception' => (string) ($job->exception ?? ''),
                'failed_at' => $failedAt,
                'is_old' => $failedAt !== null && Carbon::parse($failedAt)->lt($oldCutoff),
            ];
        });

        return response()->json([
            'data' => $items,
            'current_page' => $jobs->currentPage(),
            'last_page' => $jobs->lastPage(),
            'per_page' => $jobs->perPage(),
            'total' => $jobs->total(),
            'old_count' => $oldCount,
            'clear_after_days' => $clearAfterDays,
        ]);
    }

    /**
     * DELETE /api/v1/admin/platform-speed/failed-jobs
     * Clear failed queue rows so ops can verify a fix (all, or only older_than_days).
     */
    public function clearFailedJobs(Request $request)
    {
        if (! Schema::hasTable('failed_jobs')) {
            return response()->json([
                'message' => 'The failed_jobs table is not available on this queue connection.',
            ], 503);
        }

        $data = $request->validate([
            'all' => 'sometimes|boolean',
            'older_than_days' => 'nullable|integer|min:1|max:3650',
            'confirm' => 'required|accepted',
        ]);

        $clearAll = filter_var($data['all'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $olderThanDays = isset($data['older_than_days'])
            ? max(1, (int) $data['older_than_days'])
            : null;

        $query = DB::table('failed_jobs');
        if (! $clearAll) {
            $olderThanDays ??= $this->failedJobsClearAfterDays();
            $query->where('failed_at', '<', now()->subDays($olderThanDays));
        } else {
            $olderThanDays = null;
        }

        $deleted = (int) $query->delete();

        Log::info('queue.failed_jobs.cleared', [
            'event' => 'queue.failed_jobs.cleared',
            'deleted' => $deleted,
            'all' => $clearAll,
            'older_than_days' => $olderThanDays,
            'actor_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'deleted' => $deleted,
            'all' => $clearAll,
            'older_than_days' => $olderThanDays,
            'message' => $deleted === 0
                ? 'No failed jobs matched.'
                : ($clearAll
                    ? "Cleared {$deleted} failed job(s)."
                    : "Cleared {$deleted} failed job(s) older than {$olderThanDays} day(s)."),
        ]);
    }

    protected function failedJobsClearAfterDays(): int
    {
        return max(1, (int) config('queue.failed.clear_after_days', 30));
    }

    public function frontend(PlatformSpeedService $speed)
    {
        return response()->json($speed->frontendTab());
    }

    public function synthetic(PlatformSpeedService $speed)
    {
        return response()->json($speed->syntheticTab());
    }

    public function diagnostics(Request $request, PlatformSpeedService $speed)
    {
        return response()->json($speed->diagnosticsTab($this->range($request)));
    }

    public function storeSyntheticCheck(Request $request, PlatformSpeedSyntheticRunner $runner)
    {
        if (! Schema::hasTable('platform_speed_synthetic_checks')) {
            return response()->json(['message' => 'Synthetic tables missing. Run migrations.'], 503);
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'method' => ['sometimes', Rule::in(['GET', 'HEAD'])],
            'path' => ['required', 'string', 'max:400', function ($attr, $value, $fail) {
                $v = (string) $value;
                if (preg_match('#/(sales|paybill|mpesa|stock|orders).*?(post|create|delete)#i', $v)) {
                    $fail('Synthetic checks must be safe read-only paths.');
                }
            }],
            'interval_seconds' => 'sometimes|integer|min:30|max:3600',
            'timeout_ms' => 'sometimes|integer|min:500|max:30000',
            'expect_status' => 'sometimes|integer|min:100|max:599',
            'auth_mode' => ['sometimes', Rule::in(['none', 'token'])],
            'is_active' => 'sometimes|boolean',
        ]);

        $id = DB::table('platform_speed_synthetic_checks')->insertGetId([
            'name' => $data['name'],
            'method' => $data['method'] ?? 'GET',
            'path' => $data['path'],
            'interval_seconds' => $data['interval_seconds'] ?? 60,
            'timeout_ms' => $data['timeout_ms'] ?? 5000,
            'expect_status' => $data['expect_status'] ?? 200,
            'auth_mode' => $data['auth_mode'] ?? 'none',
            'is_active' => $data['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runner->ensureDefaults();

        return response()->json([
            'data' => (array) DB::table('platform_speed_synthetic_checks')->find($id),
        ], 201);
    }

    public function runSynthetic(PlatformSpeedSyntheticRunner $runner)
    {
        $result = $runner->runDue();

        return response()->json([
            'message' => 'Synthetic checks executed.',
            'ran' => $result['ran'],
            'failed' => $result['failed'],
        ]);
    }

    protected function range(Request $request): string
    {
        $range = (string) $request->query('range', '1h');

        return in_array($range, ['1h', '6h', '24h', '7d'], true) ? $range : '1h';
    }
}
