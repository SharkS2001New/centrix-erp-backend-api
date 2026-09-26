<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Platform\SlowApiUrlAnalyzer;
use App\Services\Platform\SlowQueryDigestService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformSlowQueryController extends Controller
{
    public function index(Request $request, SlowQueryDigestService $digest)
    {
        $data = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        return response()->json($digest->topQueries((int) ($data['limit'] ?? 25)));
    }

    public function reset(SlowQueryDigestService $digest)
    {
        $result = $digest->resetDigests();
        if (! ($result['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'digest' => $result['message'] ?? 'Could not reset digests.',
            ]);
        }

        return response()->json([
            ...$result,
            ...$digest->topQueries(25),
        ]);
    }

    public function advise(Request $request, SlowQueryDigestService $digest, AiProviderFactory $providers)
    {
        $data = $request->validate([
            'sql' => 'required|string|max:20000',
            'digest' => 'nullable|string|max:128',
            'avg_sec' => 'nullable|numeric|min:0',
            'exec_count' => 'nullable|integer|min:0',
            'rows_examined' => 'nullable|integer|min:0',
        ]);

        $heuristic = $digest->suggestFixes($data['sql']);
        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();

        if (! $runtime || empty($runtime['api_key'])) {
            return response()->json([
                'heuristic' => $heuristic,
                'advice' => [
                    'source' => 'heuristic',
                    'note' => 'Platform AI credentials are not configured. Showing rule-based advice. Add a key under Platform → AI training.',
                    ...$heuristic,
                ],
            ]);
        }

        $system = 'You are Centrix ERP MySQL performance assistant for platform admins. '
            .'Return JSON only with keys fast_fix (string array), permanent_fix (string array), '
            .'safe_sql (string array of ONLY ANALYZE TABLE, OPTIMIZE TABLE on retention tables, or CREATE/ADD INDEX), '
            .'and platform_actions (array of {id, label, kind} where kind is operational_prune or safe_sql; '
            .'if kind is safe_sql include sql). '
            .'Never suggest DROP DATABASE, unrestricted DELETE, UPDATE, TRUNCATE of live sales, or SET GLOBAL. '
            .'For hikvision_agent_commands / hikvision_access_events bloat, recommend Platform Data retention prune '
            .'(delete expired agent data) before OPTIMIZE TABLE. '
            .'Prefer Centrix hot windows of 14–90 days for reports and sales lists.';

        $userPrompt = 'avg_sec='.($data['avg_sec'] ?? 'n/a')
            .' exec_count='.($data['exec_count'] ?? 'n/a')
            .' rows_examined='.($data['rows_examined'] ?? 'n/a')."\n"
            ."SQL:\n".$data['sql'];

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
            $aiAdvice = $this->parseAiAdvice($content, $heuristic);
        } catch (\Throwable $e) {
            report($e);
            $aiAdvice = [
                'source' => 'heuristic',
                'note' => 'Centrix AI call failed; showing rule-based advice. '.$e->getMessage(),
                ...$heuristic,
            ];
        }

        return response()->json([
            'heuristic' => $heuristic,
            'advice' => $aiAdvice,
        ]);
    }

    public function adviseUrl(Request $request, SlowApiUrlAnalyzer $analyzer, AiProviderFactory $providers)
    {
        $data = $request->validate([
            'url' => 'required|string|max:2000',
            'method' => 'nullable|string|max:10',
        ]);

        $analysis = $analyzer->analyze($data['url'], $data['method'] ?? null);
        $heuristic = [
            'fast_fix' => $analysis['fast_fix'],
            'permanent_fix' => $analysis['permanent_fix'],
            'safe_sql' => $analysis['safe_sql'],
            'platform_actions' => $analysis['platform_actions'],
            'hypotheses' => $analysis['hypotheses'],
        ];

        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime || empty($runtime['api_key'])) {
            return response()->json([
                'analysis' => $analysis,
                'advice' => [
                    'source' => 'heuristic',
                    'note' => 'Platform AI credentials are not configured. Showing route + digest based advice. Add a key under Platform → AI training.',
                    ...$heuristic,
                ],
            ]);
        }

        $digestLines = '';
        foreach (array_slice($analysis['related_digests'] ?? [], 0, 5) as $i => $row) {
            $n = $i + 1;
            $digestLines .= "Digest {$n}: avg=".($row['avg_sec'] ?? '?')
                .'s total='.($row['total_sec'] ?? '?')
                .'s runs='.($row['exec_count'] ?? '?')
                .' sql='.($row['sql'] ?? '')."\n";
        }
        $issueLines = '';
        foreach (array_slice($analysis['related_issues'] ?? [], 0, 5) as $i => $row) {
            $n = $i + 1;
            $issueLines .= "Issue {$n}: ".($row['http_method'] ?? '').' '.($row['api_path'] ?? '')
                .' duration_ms='.($row['duration_ms'] ?? 'n/a')
                .' msg='.($row['message'] ?? '')."\n";
        }

        $route = $analysis['route'] ?? null;
        $system = 'You are Centrix ERP API performance assistant for platform admins. '
            .'A slow API URL was pasted. Explain likely causes and fixes. '
            .'Return JSON only with keys: hypotheses (string array), fast_fix (string array), '
            .'permanent_fix (string array), safe_sql (ANALYZE/OPTIMIZE retention tables or CREATE/ADD INDEX only), '
            .'platform_actions (array of {id,label,kind[,sql]}). '
            .'Never suggest DROP DATABASE, unrestricted DELETE/UPDATE, TRUNCATE of live sales, or SET GLOBAL. '
            .'Prefer 14–90 day report windows, organization_id filters, pagination, and Data retention prune for Hikvision/attendance.';

        $userPrompt = 'Method: '.$analysis['method']."\n"
            .'Path: '.$analysis['path']."\n"
            .'Query: '.json_encode($analysis['query'] ?? [], JSON_UNESCAPED_SLASHES)."\n"
            .'Route action: '.($route['action'] ?? 'unmatched')."\n"
            .'Controller: '.($route['controller'] ?? 'n/a').'::'.($route['action_method'] ?? '')."\n"
            .'Table hints: '.implode(', ', $analysis['table_hints'] ?? [])."\n"
            .'Heuristic hypotheses: '.implode(' | ', $analysis['hypotheses'] ?? [])."\n"
            ."Related digests:\n".($digestLines !== '' ? $digestLines : "(none)\n")
            ."Related slow issue reports:\n".($issueLines !== '' ? $issueLines : "(none)\n");

        try {
            $turn = $providers->make($runtime)->chat([
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.25,
                'max_output_tokens' => 1100,
            ]);
            $content = trim((string) ($turn['text'] ?? ''));
            $aiAdvice = $this->parseAiAdvice($content, $heuristic);
            if (! empty($analysis['hypotheses']) && empty($aiAdvice['hypotheses'])) {
                $aiAdvice['hypotheses'] = $analysis['hypotheses'];
            }
        } catch (\Throwable $e) {
            report($e);
            $aiAdvice = [
                'source' => 'heuristic',
                'note' => 'Centrix AI call failed; showing route + digest based advice. '.$e->getMessage(),
                ...$heuristic,
            ];
        }

        return response()->json([
            'analysis' => $analysis,
            'advice' => $aiAdvice,
        ]);
    }

    public function runFix(Request $request, SlowQueryDigestService $digest)
    {
        $data = $request->validate([
            'sql' => 'required|string|max:5000',
        ]);

        try {
            return response()->json($digest->runSafeFixSql($data['sql']));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'sql' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array{
     *   fast_fix: list<string>,
     *   permanent_fix: list<string>,
     *   safe_sql: list<string>,
     *   platform_actions?: list<array<string, mixed>>,
     *   hypotheses?: list<string>
     * }  $fallback
     * @return array<string, mixed>
     */
    protected function parseAiAdvice(string $content, array $fallback): array
    {
        $json = null;
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        if (! is_array($json)) {
            return [
                'source' => 'ai_text',
                'raw' => $content,
                ...$fallback,
            ];
        }

        $actions = $json['platform_actions'] ?? $fallback['platform_actions'] ?? [];
        if (! is_array($actions)) {
            $actions = $fallback['platform_actions'] ?? [];
        }

        $hypotheses = $json['hypotheses'] ?? $fallback['hypotheses'] ?? [];
        if (! is_array($hypotheses)) {
            $hypotheses = $fallback['hypotheses'] ?? [];
        }

        return [
            'source' => 'ai',
            'hypotheses' => array_values(array_filter(array_map('strval', $hypotheses))),
            'fast_fix' => array_values(array_filter(array_map('strval', $json['fast_fix'] ?? $fallback['fast_fix']))),
            'permanent_fix' => array_values(array_filter(array_map('strval', $json['permanent_fix'] ?? $fallback['permanent_fix']))),
            'safe_sql' => array_values(array_filter(array_map('strval', $json['safe_sql'] ?? $fallback['safe_sql']))),
            'platform_actions' => array_values(array_filter($actions, 'is_array')),
            'raw' => $content,
        ];
    }
}
