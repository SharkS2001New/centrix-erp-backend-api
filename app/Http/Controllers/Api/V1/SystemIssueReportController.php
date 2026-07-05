<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemIssueReport;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use App\Services\SystemIssues\SystemIssueDigestService;
use App\Services\SystemIssues\SystemIssueFingerprint;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemIssueReportController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['error', 'slow', 'user_report'])],
            'message' => 'required|string|max:500',
            'user_notes' => 'nullable|string|max:5000',
            'page_url' => 'nullable|string|max:500',
            'api_path' => 'nullable|string|max:500',
            'http_method' => 'nullable|string|max:16',
            'http_status' => 'nullable|integer|min:0|max:599',
            'duration_ms' => 'nullable|integer|min:0',
            'context' => 'nullable|array',
            'reported_by_user' => 'sometimes|boolean',
            'report_id' => 'nullable|uuid',
        ]);

        $user = $request->user();

        if (! empty($data['report_id'])) {
            $existing = SystemIssueReport::query()->find($data['report_id']);
            if ($existing && (int) $existing->user_id === (int) $user->id) {
                $existing->update([
                    'user_notes' => $data['user_notes'] ?? $existing->user_notes,
                    'reported_by_user' => true,
                ]);

                return response()->json($existing->fresh(), 200);
            }
        }

        $report = SystemIssueReport::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'kind' => $data['kind'],
            'fingerprint' => SystemIssueFingerprint::forReport(
                $data['kind'],
                $data['message'],
                $data['api_path'] ?? null,
            ),
            'status' => 'open',
            'message' => $data['message'],
            'user_notes' => $data['user_notes'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'api_path' => $data['api_path'] ?? null,
            'http_method' => $data['http_method'] ?? null,
            'http_status' => $data['http_status'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'context' => $this->sanitizeContext($data['context'] ?? null, $request),
            'reported_by_user' => (bool) ($data['reported_by_user'] ?? false),
        ]);

        app(AdminNotificationService::class)->notifySuperAdmins($user, [
            'type' => 'alert',
            'severity' => $data['kind'] === 'error' ? 'danger' : 'warning',
            'title' => 'System issue reported',
            'message' => "{$user->full_name} reported {$data['kind']}: {$data['message']}",
            'action_url' => '/platform/system-issues',
        ], InAppNotificationEvents::SYSTEM_ISSUE);

        return response()->json($report, 201);
    }

    /** @param  array<string, mixed>|null  $context */
    protected function sanitizeContext(?array $context, Request $request): ?array
    {
        $base = [
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ];

        if (! is_array($context)) {
            return $base;
        }

        unset($context['password'], $context['token'], $context['authorization']);

        return array_merge($base, $context);
    }
}
