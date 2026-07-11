<?php

namespace App\Services\SystemIssues;

use App\Models\SystemIssueReport;
use App\Models\User;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemIssueReporter
{
    public function reportException(Throwable $e, Request $request, ?User $user = null): ?SystemIssueReport
    {
        if ($this->shouldSkipRequest($request)) {
            return null;
        }

        $apiPath = '/'.ltrim($request->path(), '/');
        $technicalDetail = $this->formatException($e);
        $summary = $this->summarizeException($e);
        $fingerprint = SystemIssueFingerprint::forReport('error', $summary, $apiPath);

        try {
            $existing = SystemIssueReport::query()
                ->where('fingerprint', $fingerprint)
                ->where('status', 'open')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->first();

            if ($existing) {
                return $this->enrichExistingReport($existing, $summary, $technicalDetail);
            }

            $report = SystemIssueReport::create([
                'organization_id' => $user?->organization_id,
                'user_id' => $user?->id,
                'kind' => 'error',
                'fingerprint' => $fingerprint,
                'status' => 'open',
                'message' => mb_substr($summary, 0, 500),
                'technical_detail' => $technicalDetail,
                'page_url' => $request->header('X-Page-Url') ?: null,
                'api_path' => mb_substr($apiPath, 0, 500),
                'http_method' => mb_substr($request->method(), 0, 16),
                'http_status' => 500,
                'context' => [
                    'source' => 'server',
                    'exception_class' => $e::class,
                    'exception_message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ],
                'reported_by_user' => false,
            ]);
        } catch (Throwable $reportError) {
            Log::warning('Failed to persist system issue report', [
                'error' => $reportError->getMessage(),
                'original' => $summary,
            ]);

            return null;
        }

        $this->safeNotifySuperAdmins($report, $user);
        $this->safeInstantAlert($report);

        return $report;
    }

    protected function safeInstantAlert(SystemIssueReport $report): void
    {
        try {
            app(SystemIssueAlertService::class)->sendInstantIfNeeded($report);
        } catch (Throwable $e) {
            Log::warning('Failed to send system issue instant alert', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function enrichExistingReport(
        SystemIssueReport $report,
        string $summary,
        string $technicalDetail,
    ): SystemIssueReport {
        if ($report->technical_detail) {
            return $report;
        }

        $report->update([
            'message' => mb_substr($summary, 0, 500),
            'technical_detail' => $technicalDetail,
        ]);

        return $report->fresh();
    }

    protected function shouldSkipRequest(Request $request): bool
    {
        $path = strtolower($request->path());

        return str_contains($path, 'system-issue-reports')
            || str_contains($path, 'health');
    }

    public function summarizeException(Throwable $e): string
    {
        $class = class_basename($e);
        $message = trim($e->getMessage());
        $location = $e->getFile()
            ? sprintf(' at %s:%d', $e->getFile(), $e->getLine())
            : '';

        if ($message === '') {
            return $class.$location;
        }

        return sprintf('%s: %s%s', $class, $message, $location);
    }

    public function formatException(Throwable $e): string
    {
        $lines = [
            sprintf(
                '[object] (%s(code: %s): %s at %s:%d)',
                $e::class,
                $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ),
            '[stacktrace]',
        ];

        foreach ($e->getTrace() as $index => $frame) {
            $lines[] = $this->formatTraceFrame($index, $frame);
        }

        $previous = $e->getPrevious();
        if ($previous instanceof Throwable) {
            $lines[] = '';
            $lines[] = '[previous exception] '.$this->formatException($previous);
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed>  $frame */
    protected function formatTraceFrame(int $index, array $frame): string
    {
        $file = $frame['file'] ?? '[internal function]';
        $line = $frame['line'] ?? 0;
        $class = $frame['class'] ?? '';
        $type = $frame['type'] ?? '';
        $function = $frame['function'] ?? '';

        if ($class !== '') {
            $call = $class.$type.$function.'()';
        } else {
            $call = $function.'()';
        }

        return sprintf('#%d %s(%d): %s', $index, $file, $line, $call);
    }

    protected function safeNotifySuperAdmins(SystemIssueReport $report, ?User $actor): void
    {
        if (! $actor) {
            return;
        }

        try {
            app(AdminNotificationService::class)->notifySuperAdmins($actor, [
                'type' => 'alert',
                'severity' => 'danger',
                'title' => 'Server error captured',
                'message' => mb_substr((string) $report->message, 0, 240),
                'action_url' => '/platform/system-issues',
            ], InAppNotificationEvents::SYSTEM_ISSUE);
        } catch (Throwable $e) {
            Log::warning('Failed to notify super admins about system issue', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
