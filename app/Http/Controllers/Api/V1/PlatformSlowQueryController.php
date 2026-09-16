<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
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
     *   platform_actions?: list<array<string, mixed>>
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

        return [
            'source' => 'ai',
            'fast_fix' => array_values(array_filter(array_map('strval', $json['fast_fix'] ?? $fallback['fast_fix']))),
            'permanent_fix' => array_values(array_filter(array_map('strval', $json['permanent_fix'] ?? $fallback['permanent_fix']))),
            'safe_sql' => array_values(array_filter(array_map('strval', $json['safe_sql'] ?? $fallback['safe_sql']))),
            'platform_actions' => array_values(array_filter($actions, 'is_array')),
            'raw' => $content,
        ];
    }
}
