<?php

namespace App\Services\Fulfillment;

use App\Models\Driver;
use App\Models\MobileDriverAttendanceSession;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\CapabilityGate;
use App\Support\UploadedImageProcessor;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class MobileDriverAttendanceService
{
    public const SIGN_IN_BLOCKED_MESSAGE = 'You have already ended your shift today. Contact your organization manager before you can sign in again.';

    public function __construct(
        protected MobileDriverService $driverService,
        protected UserAccessService $access,
    ) {}

    public function isEnabled(CapabilityGate $gate): bool
    {
        if (! $gate->driverMobileEnabled()) {
            return false;
        }

        $distribution = $gate->distributionSettings();

        return (bool) ($distribution['mobile_enable_driver_attendance'] ?? false);
    }

    public function openSessionForUser(User $user): ?MobileDriverAttendanceSession
    {
        return MobileDriverAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNull('sign_out_at')
            ->orderByDesc('sign_in_at')
            ->first();
    }

    public function activeSessionForUser(User $user): ?MobileDriverAttendanceSession
    {
        return MobileDriverAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNull('sign_out_at')
            ->whereNull('suspended_at')
            ->orderByDesc('sign_in_at')
            ->first();
    }

    public function suspendedSessionForUser(User $user, ?Carbon $day = null): ?MobileDriverAttendanceSession
    {
        $day ??= now();

        return MobileDriverAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNull('sign_out_at')
            ->whereNotNull('suspended_at')
            ->whereDate('sign_in_at', $day->toDateString())
            ->orderByDesc('sign_in_at')
            ->first();
    }

    public function hasClosedSessionToday(User $user, ?Carbon $day = null): bool
    {
        $day ??= now();

        return MobileDriverAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('sign_out_at')
            ->whereDate('sign_in_at', $day->toDateString())
            ->exists();
    }

    /** @param  array<string, mixed>  $data */
    public function signIn(User $user, CapabilityGate $gate, array $data): MobileDriverAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Driver attendance is not enabled for this organization.');
        }

        $driver = $this->driverService->requireDriver($user);

        $existing = $this->openSessionForUser($user);
        if ($existing) {
            if ($existing->isSuspended()) {
                throw new InvalidArgumentException('You have a suspended session today. Resume it instead of signing in again.');
            }

            throw new InvalidArgumentException('You already have an active sign-in session.');
        }

        if ($this->hasClosedSessionToday($user)) {
            throw new InvalidArgumentException(self::SIGN_IN_BLOCKED_MESSAGE);
        }

        $photo = $data['photo'] ?? null;
        if (! $photo instanceof UploadedFile) {
            throw new InvalidArgumentException('A sign-in photo is required.');
        }

        return DB::transaction(function () use ($user, $driver, $data, $photo) {
            $photoPath = $this->storePhoto($photo, $user, 'sign-in');

            return MobileDriverAttendanceSession::create([
                'organization_id' => (int) $user->organization_id,
                'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
                'user_id' => (int) $user->id,
                'driver_id' => (int) $driver->id,
                'employee_id' => $driver->employee_id ? (int) $driver->employee_id : null,
                'sign_in_at' => now(),
                'sign_in_latitude' => (float) $data['latitude'],
                'sign_in_longitude' => (float) $data['longitude'],
                'sign_in_address' => $this->trimAddress($data['address'] ?? null),
                'sign_in_photo_path' => $photoPath,
                'device_identifier' => $this->trimDeviceId($data['device_identifier'] ?? null),
                'last_resumed_at' => now(),
            ]);
        });
    }

    public function suspend(User $user, CapabilityGate $gate): MobileDriverAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Driver attendance is not enabled for this organization.');
        }

        $session = $this->activeSessionForUser($user);
        if (! $session) {
            throw new InvalidArgumentException('No active sign-in session found to suspend.');
        }

        return DB::transaction(function () use ($session) {
            $session->fill([
                'accumulated_work_seconds' => $this->workSeconds($session),
                'suspended_at' => now(),
            ]);
            $session->save();

            return $session->fresh();
        });
    }

    public function resume(User $user, CapabilityGate $gate): MobileDriverAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Driver attendance is not enabled for this organization.');
        }

        $session = $this->suspendedSessionForUser($user);
        if (! $session) {
            throw new InvalidArgumentException('No suspended session found for today.');
        }

        return DB::transaction(function () use ($session) {
            $this->accumulateSuspendedSegment($session);

            $session->fill([
                'suspended_at' => null,
                'last_resumed_at' => now(),
            ]);
            $session->save();

            return $session->fresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function signOut(User $user, CapabilityGate $gate, array $data): MobileDriverAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Driver attendance is not enabled for this organization.');
        }

        $session = $this->openSessionForUser($user);
        if (! $session) {
            throw new InvalidArgumentException('No active sign-in session found.');
        }

        $photo = $data['photo'] ?? null;
        if (! $photo instanceof UploadedFile) {
            throw new InvalidArgumentException('A sign-out photo is required.');
        }

        return DB::transaction(function () use ($session, $user, $data, $photo) {
            if ($session->isSuspended()) {
                $this->accumulateSuspendedSegment($session);
            }

            $photoPath = $this->storePhoto($photo, $user, 'sign-out');
            $workSeconds = $this->workSeconds($session);

            $session->fill([
                'sign_out_at' => now(),
                'sign_out_latitude' => (float) $data['latitude'],
                'sign_out_longitude' => (float) $data['longitude'],
                'sign_out_address' => $this->trimAddress($data['address'] ?? null),
                'sign_out_photo_path' => $photoPath,
                'accumulated_work_seconds' => $workSeconds,
                'suspended_at' => null,
                'close_reason' => MobileDriverAttendanceSession::CLOSE_REASON_SIGN_OUT,
            ]);
            $session->save();

            return $session->fresh();
        });
    }

    /** @return array<string, mixed> */
    public function userDaySummary(User $user, CapabilityGate $gate, ?Carbon $day = null): array
    {
        $day ??= now();
        $enabled = $this->isEnabled($gate);

        if (! $enabled) {
            return [
                'feature_enabled' => false,
                'session' => null,
                'sessions_today' => 0,
                'completed_sessions_today' => 0,
                'total_work_seconds' => 0,
                'total_work_label' => '0:00',
                'sign_in_allowed' => false,
                'requires_admin_reopen' => false,
                'blocked_message' => null,
            ];
        }

        $sessions = MobileDriverAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereDate('sign_in_at', $day->toDateString())
            ->orderBy('sign_in_at')
            ->get();

        $totalSeconds = 0;
        $completed = 0;
        foreach ($sessions as $session) {
            $totalSeconds += $this->workSeconds($session);
            if ($session->isClosed()) {
                $completed++;
            }
        }

        $openSession = $this->openSessionForUser($user);
        $requiresAdminReopen = $openSession === null && $completed > 0;
        $signInAllowed = $openSession === null && $completed === 0;

        return [
            'feature_enabled' => true,
            'session' => $openSession ? $this->serializeSession($openSession) : null,
            'sessions_today' => $sessions->count(),
            'completed_sessions_today' => $completed,
            'total_work_seconds' => $totalSeconds,
            'total_work_label' => $this->formatWorkDuration($totalSeconds),
            'sign_in_allowed' => $signInAllowed,
            'requires_admin_reopen' => $requiresAdminReopen,
            'blocked_message' => $requiresAdminReopen ? self::SIGN_IN_BLOCKED_MESSAGE : null,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeSession(MobileDriverAttendanceSession $session): array
    {
        $workSeconds = $this->workSeconds($session);

        return [
            'id' => $session->id,
            'user_id' => $session->user_id,
            'driver_id' => $session->driver_id,
            'sign_in_at' => $session->sign_in_at?->toIso8601String(),
            'sign_out_at' => $session->sign_out_at?->toIso8601String(),
            'suspended_at' => $session->suspended_at?->toIso8601String(),
            'last_resumed_at' => $session->last_resumed_at?->toIso8601String(),
            'sign_in_latitude' => $session->sign_in_latitude,
            'sign_in_longitude' => $session->sign_in_longitude,
            'sign_out_latitude' => $session->sign_out_latitude,
            'sign_out_longitude' => $session->sign_out_longitude,
            'sign_in_address' => $session->sign_in_address,
            'sign_out_address' => $session->sign_out_address,
            'is_open' => $session->isOpen(),
            'is_active' => $session->isActive(),
            'is_suspended' => $session->isSuspended(),
            'status' => $session->isClosed()
                ? 'closed'
                : ($session->isSuspended() ? 'suspended' : 'active'),
            'work_seconds' => $workSeconds,
            'work_label' => $this->formatWorkDuration($workSeconds),
            'source' => 'driver',
            'source_label' => 'Driver',
        ];
    }

    public function workSeconds(MobileDriverAttendanceSession $session, ?Carbon $until = null): int
    {
        if ($session->isClosed()) {
            return max(0, (int) $session->accumulated_work_seconds);
        }

        $until ??= now();
        $start = $session->last_resumed_at ?? $session->sign_in_at;
        $segment = max(0, $start->diffInSeconds($until));

        return max(0, (int) $session->accumulated_work_seconds) + $segment;
    }

    protected function accumulateSuspendedSegment(
        MobileDriverAttendanceSession $session,
        ?Carbon $until = null,
    ): void {
        if (! $session->isSuspended() || ! $session->suspended_at) {
            return;
        }

        $until ??= now();
        $segment = max(0, $session->suspended_at->diffInSeconds($until));
        if ($segment <= 0) {
            return;
        }

        $session->accumulated_suspended_seconds = max(0, (int) $session->accumulated_suspended_seconds) + $segment;
        $session->suspended_at = null;
    }

    protected function formatWorkDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%d:%02d', $hours, $minutes);
    }

    protected function storePhoto(UploadedFile $photo, User $user, string $kind): string
    {
        $path = app(UploadedImageProcessor::class)->storePublicImagePath(
            $photo,
            "mobile-driver-attendance/{$user->organization_id}/{$user->id}/{$kind}",
        );

        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException(
                'Unable to save the attendance photo. Ensure API storage is writable and linked (php artisan storage:link).',
            );
        }

        return $path;
    }

    protected function trimAddress(mixed $value): ?string
    {
        $address = trim((string) ($value ?? ''));

        return $address === '' ? null : mb_substr($address, 0, 500);
    }

    protected function trimDeviceId(mixed $value): ?string
    {
        $deviceId = trim((string) ($value ?? ''));

        return $deviceId === '' ? null : mb_substr($deviceId, 0, 100);
    }

    /** @param  array<string, mixed>  $filters */
    public function paginateForViewer(User $viewer, CapabilityGate $gate, array $filters = []): LengthAwarePaginator
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Driver attendance is not enabled for this organization.');
        }

        $query = MobileDriverAttendanceSession::query()
            ->with(['user:id,username,full_name,branch_id', 'driver:id,full_name,driver_code'])
            ->where('organization_id', (int) $viewer->organization_id)
            ->orderByDesc('sign_in_at');

        $this->access->scopeBranchIfLimited($query, $viewer);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('sign_in_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('sign_in_at', '<=', $filters['to_date']);
        }

        if (array_key_exists('open_only', $filters)) {
            $openOnly = filter_var($filters['open_only'], FILTER_VALIDATE_BOOLEAN);
            $query->when($openOnly, fn (Builder $q) => $q->whereNull('sign_out_at'));
        }

        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 25)));

        return $query->paginate($perPage);
    }

    public function findForViewer(User $viewer, int $sessionId): MobileDriverAttendanceSession
    {
        $query = MobileDriverAttendanceSession::query()
            ->with(['user:id,username,full_name,branch_id', 'driver:id,full_name,driver_code'])
            ->where('organization_id', (int) $viewer->organization_id)
            ->whereKey($sessionId);

        $this->access->scopeBranchIfLimited($query, $viewer);

        $session = $query->first();
        if (! $session) {
            throw new InvalidArgumentException('Driver attendance session not found.');
        }

        return $session;
    }

    public function reopenSession(User $editor, CapabilityGate $gate, int $sessionId): MobileDriverAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Driver attendance is not enabled for this organization.');
        }

        $session = $this->findForViewer($editor, $sessionId);

        if (! $session->isClosed()) {
            throw new InvalidArgumentException('Only closed sessions can be reopened.');
        }

        if (! $session->sign_in_at?->isSameDay(now())) {
            throw new InvalidArgumentException('Sessions can only be reopened on the same calendar day as sign-in.');
        }

        if ($this->openSessionForUser($session->user)) {
            throw new InvalidArgumentException('This driver already has an open session today.');
        }

        return DB::transaction(function () use ($session, $editor) {
            $closedAt = $session->sign_out_at;
            $gapSeconds = $closedAt ? max(0, $closedAt->diffInSeconds(now())) : 0;

            $session->fill([
                'sign_out_at' => null,
                'sign_out_latitude' => null,
                'sign_out_longitude' => null,
                'sign_out_address' => null,
                'sign_out_photo_path' => null,
                'close_reason' => null,
                'suspended_at' => null,
                'last_resumed_at' => now(),
                'reopened_at' => now(),
                'reopened_by_user_id' => (int) $editor->id,
                'accumulated_suspended_seconds' => max(0, (int) $session->accumulated_suspended_seconds) + $gapSeconds,
            ]);
            $session->save();

            return $session->fresh(['user:id,username,full_name,branch_id', 'driver:id,full_name,driver_code']);
        });
    }
}
