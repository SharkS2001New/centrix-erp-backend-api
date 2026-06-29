<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemIssueReport;
use App\Services\SystemIssues\SystemIssueDigestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformSystemIssueReportController extends Controller
{
    public function __construct(protected SystemIssueDigestService $digest) {}

    public function index(Request $request)
    {
        $threshold = $this->digest->repeatThreshold();
        $windowDays = $this->digest->repeatWindowDays();
        $since = now()->subDays($windowDays);
        $highPriority = $this->digest->highPriorityFingerprints();

        $occurrenceSub = SystemIssueReport::query()
            ->from('system_issue_reports as sis_counts')
            ->selectRaw('count(*)')
            ->whereColumn('sis_counts.fingerprint', 'system_issue_reports.fingerprint')
            ->where('sis_counts.status', '!=', 'resolved')
            ->where('sis_counts.created_at', '>=', $since);

        $query = SystemIssueReport::query()
            ->with([
                'organization:id,org_name,company_code',
                'user:id,username,full_name',
            ])
            ->select('system_issue_reports.*')
            ->selectSub($occurrenceSub, 'occurrence_count');

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('kind') && $request->input('kind') !== 'all') {
            $query->where('kind', $request->input('kind'));
        }

        if ($request->input('priority') === 'high') {
            if ($highPriority === []) {
                $query->whereRaw('0 = 1');
            } else {
                $query->whereIn('fingerprint', $highPriority);
            }
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->input('organization_id'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('message', 'like', "%{$q}%")
                    ->orWhere('user_notes', 'like', "%{$q}%")
                    ->orWhere('page_url', 'like', "%{$q}%")
                    ->orWhere('api_path', 'like', "%{$q}%")
                    ->orWhere('id', 'like', "%{$q}%");
            });
        }

        if ($highPriority !== []) {
            $placeholders = implode(',', array_fill(0, count($highPriority), '?'));
            $query->orderByRaw(
                "CASE WHEN fingerprint IN ({$placeholders}) THEN 0 ELSE 1 END",
                $highPriority,
            );
        }

        $query->orderByDesc('occurrence_count')
            ->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 25), 100);

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function (SystemIssueReport $report) use ($highPriority, $threshold) {
            $count = (int) ($report->occurrence_count ?? 1);
            $report->setAttribute('occurrence_count', $count);
            $report->setAttribute(
                'is_high_priority',
                $report->fingerprint
                    && in_array($report->fingerprint, $highPriority, true)
                    && $count >= $threshold,
            );

            return $report;
        });

        return response()->json($paginator);
    }

    public function show(string $id)
    {
        $report = SystemIssueReport::query()
            ->with([
                'organization:id,org_name,company_code',
                'user:id,username,full_name',
                'resolvedBy:id,username,full_name',
            ])
            ->findOrFail($id);

        $count = $report->fingerprint
            ? $this->digest->occurrenceCountForFingerprint($report->fingerprint)
            : 1;
        $report->setAttribute('occurrence_count', $count);
        $report->setAttribute(
            'is_high_priority',
            $count >= $this->digest->repeatThreshold(),
        );

        return response()->json($report);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'acknowledged', 'resolved'])],
            'resolution_notes' => 'nullable|string|max:5000',
        ]);

        $report = SystemIssueReport::query()->findOrFail($id);
        $next = [];

        if (array_key_exists('status', $data)) {
            $next['status'] = $data['status'];
            if ($data['status'] === 'resolved') {
                $next['resolved_at'] = now();
                $next['resolved_by_user_id'] = $request->user()->id;
            } elseif ($report->status === 'resolved') {
                $next['resolved_at'] = null;
                $next['resolved_by_user_id'] = null;
            }
        }

        if (array_key_exists('resolution_notes', $data)) {
            $next['resolution_notes'] = $data['resolution_notes'];
        }

        $report->update($next);

        return response()->json($report->fresh([
            'organization:id,org_name,company_code',
            'user:id,username,full_name',
            'resolvedBy:id,username,full_name',
        ]));
    }

    public function summary()
    {
        return response()->json($this->digest->summaryCounts());
    }
}
