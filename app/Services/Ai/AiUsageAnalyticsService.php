<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiUsageAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function platformSummary(?string $from = null, ?string $to = null, ?int $organizationId = null): array
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            return [
                'available' => false,
                'message' => 'AI usage logging is not installed yet.',
            ];
        }

        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(30)->startOfDay();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        $base = AiUsageLog::query()
            ->whereBetween('created_at', [$fromDate, $toDate]);
        if ($organizationId) {
            $base->where('organization_id', $organizationId);
        }

        $totals = (clone $base)
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw("SUM(CASE WHEN status != 'ok' THEN 1 ELSE 0 END) as error_count")
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('AVG(latency_ms) as avg_latency_ms')
            ->first();

        $byDay = (clone $base)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('AVG(latency_ms) as avg_latency_ms')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'requests' => (int) $row->requests,
                'ok_count' => (int) $row->ok_count,
                'total_tokens' => (int) $row->total_tokens,
                'avg_latency_ms' => $row->avg_latency_ms !== null ? (int) round((float) $row->avg_latency_ms) : null,
            ])
            ->all();

        $byOrg = (clone $base)
            ->selectRaw('organization_id')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw("SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END) as ok_count")
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('AVG(latency_ms) as avg_latency_ms')
            ->groupBy('organization_id')
            ->orderByDesc('requests')
            ->limit(25)
            ->get();

        $orgNames = Organization::query()
            ->whereIn('id', $byOrg->pluck('organization_id')->filter()->all())
            ->pluck('org_name', 'id');

        $byOrganization = $byOrg->map(fn ($row) => [
            'organization_id' => (int) $row->organization_id,
            'organization_name' => $orgNames[(int) $row->organization_id] ?? ('Org #'.$row->organization_id),
            'requests' => (int) $row->requests,
            'ok_count' => (int) $row->ok_count,
            'total_tokens' => (int) $row->total_tokens,
            'avg_latency_ms' => $row->avg_latency_ms !== null ? (int) round((float) $row->avg_latency_ms) : null,
        ])->all();

        $byProvider = (clone $base)
            ->selectRaw('provider')
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->groupBy('provider')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($row) => [
                'provider' => (string) $row->provider,
                'requests' => (int) $row->requests,
                'total_tokens' => (int) $row->total_tokens,
            ])
            ->all();

        $toolCounts = [];
        (clone $base)
            ->whereNotNull('tools_used')
            ->orderByDesc('id')
            ->limit(2000)
            ->get(['tools_used'])
            ->each(function (AiUsageLog $log) use (&$toolCounts) {
                foreach ((array) $log->tools_used as $tool) {
                    $name = is_string($tool) ? $tool : '';
                    if ($name === '') {
                        continue;
                    }
                    $toolCounts[$name] = ($toolCounts[$name] ?? 0) + 1;
                }
            });
        arsort($toolCounts);
        $topTools = [];
        foreach (array_slice($toolCounts, 0, 15, true) as $name => $count) {
            $topTools[] = ['tool' => $name, 'count' => $count];
        }

        $activeOrgs = (clone $base)->distinct('organization_id')->count('organization_id');

        return [
            'available' => true,
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'summary' => [
                'requests' => (int) ($totals->requests ?? 0),
                'ok_count' => (int) ($totals->ok_count ?? 0),
                'error_count' => (int) ($totals->error_count ?? 0),
                'input_tokens' => (int) ($totals->input_tokens ?? 0),
                'output_tokens' => (int) ($totals->output_tokens ?? 0),
                'total_tokens' => (int) ($totals->total_tokens ?? 0),
                'avg_latency_ms' => $totals->avg_latency_ms !== null ? (int) round((float) $totals->avg_latency_ms) : null,
                'active_organizations' => $activeOrgs,
            ],
            'by_day' => $byDay,
            'by_organization' => $byOrganization,
            'by_provider' => $byProvider,
            'top_tools' => $topTools,
            'default_tools' => config('ai.tools', []),
        ];
    }
}
