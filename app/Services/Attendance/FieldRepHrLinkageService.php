<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\MobileRepAttendanceSession;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FieldRepHrLinkageService
{
    public const STATUS_LINKED = 'linked';

    public const STATUS_NO_EMPLOYEE = 'no_employee';

    public const STATUS_INACTIVE_EMPLOYEE = 'inactive_employee';

    public function __construct(protected UserAccessService $access) {}

    public function employeeForUser(User $user): ?Employee
    {
        return Employee::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->first();
    }

    /** @return array<string, mixed> */
    public function describeUserLink(User $user, ?Employee $employee = null): array
    {
        if (func_num_args() === 1) {
            $employee = $this->employeeForUser($user);
        }

        if (! $employee) {
            return $this->linkPayload(
                false,
                self::STATUS_NO_EMPLOYEE,
                null,
                null,
                'Link this mobile login to an employee profile (HR → Employees → Linked system user) so field attendance counts in HR and payroll.',
            );
        }

        if (! $employee->is_active || $employee->employment_status !== 'active') {
            return $this->linkPayload(
                false,
                self::STATUS_INACTIVE_EMPLOYEE,
                (int) $employee->id,
                $employee->full_name,
                'Employee profile is inactive. Reactivate the employee or link a different system user.',
            );
        }

        return $this->linkPayload(
            true,
            self::STATUS_LINKED,
            (int) $employee->id,
            $employee->full_name,
            null,
        );
    }

    /**
     * @param  Collection<int, MobileRepAttendanceSession>  $sessions
     * @return array<int, array<string, mixed>>
     */
    public function linksForSessions(Collection $sessions, int $organizationId): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }

        $userIds = $sessions->pluck('user_id')->unique()->filter()->values();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('organization_id', $organizationId)
            ->get()
            ->keyBy('id');

        $employees = Employee::query()
            ->whereIn('user_id', $userIds)
            ->where('organization_id', $organizationId)
            ->get()
            ->keyBy('user_id');

        $links = [];
        foreach ($userIds as $userId) {
            $user = $users->get($userId);
            if (! $user) {
                continue;
            }

            $links[(int) $userId] = $this->describeUserLink($user, $employees->get($userId));
        }

        return $links;
    }

    /**
     * @return array{
     *   attention_needed: bool,
     *   unlinked_rep_count: int,
     *   lookback_days: int,
     *   message: string|null,
     *   reps: list<array<string, mixed>>
     * }
     */
    public function attentionSummary(User $viewer, int $lookbackDays = 30): array
    {
        $lookbackDays = max(1, min(365, $lookbackDays));
        $since = Carbon::now()->subDays($lookbackDays)->toDateString();
        $orgId = (int) $viewer->organization_id;

        $sessionQuery = MobileRepAttendanceSession::query()
            ->where('organization_id', $orgId)
            ->whereDate('sign_in_at', '>=', $since);

        $this->access->scopeBranchIfLimited($sessionQuery, $viewer);

        $stats = $sessionQuery
            ->selectRaw('user_id, COUNT(*) as session_count, MAX(sign_in_at) as last_session_at')
            ->groupBy('user_id')
            ->get();

        if ($stats->isEmpty()) {
            return [
                'attention_needed' => false,
                'unlinked_rep_count' => 0,
                'lookback_days' => $lookbackDays,
                'message' => null,
                'reps' => [],
            ];
        }

        $userIds = $stats->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('organization_id', $orgId)
            ->get()
            ->keyBy('id');

        $employees = Employee::query()
            ->whereIn('user_id', $userIds)
            ->where('organization_id', $orgId)
            ->get()
            ->keyBy('user_id');

        $reps = [];
        foreach ($stats as $row) {
            $userId = (int) $row->user_id;
            $user = $users->get($userId);
            if (! $user) {
                continue;
            }

            $link = $this->describeUserLink($user, $employees->get($userId));
            if ($link['counts_toward_payroll']) {
                continue;
            }

            $reps[] = array_merge($link, [
                'user_id' => $userId,
                'user_name' => $user->full_name ?: $user->username,
                'username' => $user->username,
                'session_count' => (int) $row->session_count,
                'last_session_at' => $row->last_session_at
                    ? Carbon::parse($row->last_session_at)->toIso8601String()
                    : null,
            ]);
        }

        usort($reps, fn (array $a, array $b) => ($b['session_count'] ?? 0) <=> ($a['session_count'] ?? 0));

        $count = count($reps);

        return [
            'attention_needed' => $count > 0,
            'unlinked_rep_count' => $count,
            'lookback_days' => $lookbackDays,
            'message' => $count > 0
                ? $this->attentionMessage($count)
                : null,
            'reps' => $reps,
        ];
    }

    public function activeEmployeeForUser(User $user): ?Employee
    {
        $link = $this->describeUserLink($user);

        if (! $link['counts_toward_payroll']) {
            return null;
        }

        return Employee::query()
            ->with('shift')
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->where('employment_status', 'active')
            ->first();
    }

    /** @return array<string, mixed> */
    protected function linkPayload(
        bool $counts,
        string $status,
        ?int $employeeId,
        ?string $employeeName,
        ?string $hint,
    ): array {
        return [
            'counts_toward_payroll' => $counts,
            'status' => $status,
            'employee_id' => $employeeId,
            'employee_name' => $employeeName,
            'hint' => $hint,
        ];
    }

    protected function attentionMessage(int $count): string
    {
        $repWord = $count === 1 ? 'rep has' : 'reps have';

        return "{$count} field {$repWord} mobile attendance but no active employee link — those sessions will not count in HR or payroll until you connect the login to an employee profile.";
    }
}
