<?php

namespace App\Services\SystemIssues;

use App\Models\SystemIssueReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class SystemIssueDigestService
{
    public function repeatThreshold(): int
    {
        return max(2, (int) config('system_issues.repeat_threshold', 3));
    }

    public function repeatWindowDays(): int
    {
        return max(1, (int) config('system_issues.repeat_window_days', 7));
    }

    /** @return list<string> */
    public function highPriorityFingerprints(): array
    {
        $since = now()->subDays($this->repeatWindowDays());

        return SystemIssueReport::query()
            ->where('status', '!=', 'resolved')
            ->where('created_at', '>=', $since)
            ->whereNotNull('fingerprint')
            ->groupBy('fingerprint')
            ->havingRaw('COUNT(*) >= ?', [$this->repeatThreshold()])
            ->pluck('fingerprint')
            ->all();
    }

    public function occurrenceCountForFingerprint(string $fingerprint): int
    {
        return SystemIssueReport::query()
            ->where('fingerprint', $fingerprint)
            ->where('status', '!=', 'resolved')
            ->where('created_at', '>=', now()->subDays($this->repeatWindowDays()))
            ->count();
    }

    /** @return array{first_seen_at: ?string, last_seen_at: ?string} */
    public function seenBoundsForFingerprint(?string $fingerprint): array
    {
        if (! $fingerprint) {
            return ['first_seen_at' => null, 'last_seen_at' => null];
        }

        $since = now()->subDays($this->repeatWindowDays());
        $bounds = SystemIssueReport::query()
            ->where('fingerprint', $fingerprint)
            ->where('status', '!=', 'resolved')
            ->where('created_at', '>=', $since)
            ->selectRaw('MIN(created_at) as first_seen_at, MAX(created_at) as last_seen_at')
            ->first();

        return [
            'first_seen_at' => $bounds?->first_seen_at
                ? \Illuminate\Support\Carbon::parse($bounds->first_seen_at)->toIso8601String()
                : null,
            'last_seen_at' => $bounds?->last_seen_at
                ? \Illuminate\Support\Carbon::parse($bounds->last_seen_at)->toIso8601String()
                : null,
        ];
    }

    /** Eloquent subquery: earliest occurrence created_at for the same fingerprint (windowed). */
    public function firstSeenSubquery()
    {
        $since = now()->subDays($this->repeatWindowDays());

        return SystemIssueReport::query()
            ->from('system_issue_reports as sis_first')
            ->selectRaw('MIN(sis_first.created_at)')
            ->whereColumn('sis_first.fingerprint', 'system_issue_reports.fingerprint')
            ->where('sis_first.status', '!=', 'resolved')
            ->where('sis_first.created_at', '>=', $since);
    }

    /** Eloquent subquery: latest occurrence created_at for the same fingerprint (windowed). */
    public function lastSeenSubquery()
    {
        $since = now()->subDays($this->repeatWindowDays());

        return SystemIssueReport::query()
            ->from('system_issue_reports as sis_last')
            ->selectRaw('MAX(sis_last.created_at)')
            ->whereColumn('sis_last.fingerprint', 'system_issue_reports.fingerprint')
            ->where('sis_last.status', '!=', 'resolved')
            ->where('sis_last.created_at', '>=', $since);
    }

    /** @return array{open: int, acknowledged: int, resolved: int, today: int, high_priority: int} */
    public function summaryCounts(): array
    {
        $highPriority = count($this->highPriorityFingerprints());

        return [
            'open' => SystemIssueReport::query()->where('status', 'open')->count(),
            'acknowledged' => SystemIssueReport::query()->where('status', 'acknowledged')->count(),
            'resolved' => SystemIssueReport::query()->where('status', 'resolved')->count(),
            'today' => SystemIssueReport::query()->whereDate('created_at', today())->count(),
            'high_priority' => $highPriority,
        ];
    }

    /** @return Collection<int, SystemIssueReport> */
    public function openIssuesForDigest(int $limit = 50): Collection
    {
        $highPriority = $this->highPriorityFingerprints();

        $query = SystemIssueReport::query()
            ->with([
                'organization:id,org_name,company_code',
                'user:id,username,full_name',
            ])
            ->whereIn('status', ['open', 'acknowledged']);

        if ($highPriority !== []) {
            $query->orderByRaw(
                'CASE WHEN fingerprint IN ('.implode(',', array_fill(0, count($highPriority), '?')).') THEN 0 ELSE 1 END',
                $highPriority,
            );
        }

        return $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(function (SystemIssueReport $report) use ($highPriority) {
                $report->setAttribute(
                    'occurrence_count',
                    $report->fingerprint
                        ? $this->occurrenceCountForFingerprint($report->fingerprint)
                        : 1,
                );
                $report->setAttribute(
                    'is_high_priority',
                    $report->fingerprint && in_array($report->fingerprint, $highPriority, true),
                );

                return $report;
            });
    }

    public function sendDailyDigest(?string $recipient = null): bool
    {
        $to = trim((string) ($recipient ?? ''));
        if ($to === '') {
            if (! SystemIssueAlertSettingsResolver::forPlatform()['email_digest_enabled']) {
                return false;
            }
            $to = SystemIssueAlertSettingsResolver::digestEmail();
        }
        if ($to === '') {
            return false;
        }

        $summary = $this->summaryCounts();
        $issues = $this->openIssuesForDigest();
        $highPriorityGroups = $this->highPriorityGroups();

        $subject = sprintf(
            '[Centrix ERP] System issues digest — %d open, %d high priority',
            $summary['open'] + $summary['acknowledged'],
            $summary['high_priority'],
        );

        $body = $this->buildDigestBody($summary, $issues, $highPriorityGroups);

        Mail::raw($body, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        return true;
    }

    /** @return list<array{fingerprint: string, count: int, sample_message: string, sample_api: ?string}> */
    public function highPriorityGroups(): array
    {
        $since = now()->subDays($this->repeatWindowDays());
        $threshold = $this->repeatThreshold();

        $groups = SystemIssueReport::query()
            ->selectRaw('fingerprint, COUNT(*) as occurrence_count, MIN(message) as sample_message, MIN(api_path) as sample_api')
            ->where('status', '!=', 'resolved')
            ->where('created_at', '>=', $since)
            ->whereNotNull('fingerprint')
            ->groupBy('fingerprint')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->orderByDesc('occurrence_count')
            ->limit(25)
            ->get();

        return $groups->map(fn ($row) => [
            'fingerprint' => (string) $row->fingerprint,
            'count' => (int) $row->occurrence_count,
            'sample_message' => (string) $row->sample_message,
            'sample_api' => $row->sample_api,
        ])->all();
    }

    /** @param  array{open: int, acknowledged: int, resolved: int, today: int, high_priority: int}  $summary
     * @param  Collection<int, SystemIssueReport>  $issues
     * @param  list<array{fingerprint: string, count: int, sample_message: string, sample_api: ?string}>  $highPriorityGroups
     */
    protected function buildDigestBody(array $summary, Collection $issues, array $highPriorityGroups): string
    {
        $lines = [];
        $lines[] = 'Centrix ERP — daily system issues digest';
        $lines[] = 'Generated: '.now()->toDateTimeString();
        $lines[] = '';
        $lines[] = 'Summary';
        $lines[] = '-------';
        $lines[] = 'Open: '.$summary['open'];
        $lines[] = 'Acknowledged: '.$summary['acknowledged'];
        $lines[] = 'Resolved (all time): '.$summary['resolved'];
        $lines[] = 'Reported today: '.$summary['today'];
        $lines[] = 'High priority (repetitive): '.$summary['high_priority'];
        $lines[] = '';

        if ($highPriorityGroups !== []) {
            $lines[] = 'High priority — repetitive issues';
            $lines[] = '--------------------------------';
            foreach ($highPriorityGroups as $group) {
                $api = $group['sample_api'] ? ' ['.$group['sample_api'].']' : '';
                $lines[] = sprintf('- %dx %s%s', $group['count'], $group['sample_message'], $api);
            }
            $lines[] = '';
        }

        if ($issues->isNotEmpty()) {
            $lines[] = 'Recent open / acknowledged issues';
            $lines[] = '---------------------------------';
            foreach ($issues as $issue) {
                $org = $issue->organization?->company_code ?? '—';
                $flag = $issue->getAttribute('is_high_priority') ? '[HIGH] ' : '';
                $lines[] = sprintf(
                    '%s%s | %s | %s | %s',
                    $flag,
                    strtoupper((string) $issue->kind),
                    $org,
                    $issue->created_at?->toDateTimeString() ?? '—',
                    $issue->message,
                );
            }
        } else {
            $lines[] = 'No open issues at digest time.';
        }

        $lines[] = '';
        $lines[] = 'Review in Centrix: Platform → System errors & reports';

        return implode("\n", $lines);
    }
}
