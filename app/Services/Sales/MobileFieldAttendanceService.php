<?php

namespace App\Services\Sales;

use App\Models\MobileRepAttendanceSession;
use App\Models\User;
use App\Services\Attendance\FieldRepAttendanceHrSync;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\CapabilityGate;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class MobileFieldAttendanceService
{
    public const SIGN_IN_BLOCKED_MESSAGE = 'You have already ended your work session today. Contact your organization manager to reopen your session before you can continue working.';

    public function __construct(protected UserAccessService $access) {}

    public function isEnabled(CapabilityGate $gate): bool
    {
        $sales = $gate->moduleSettings('sales');

        return (bool) ($sales['mobile_enable_field_attendance'] ?? false);
    }

    public function openSessionForUser(User $user): ?MobileRepAttendanceSession
    {
        return MobileRepAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNull('sign_out_at')
            ->orderByDesc('sign_in_at')
            ->first();
    }

    public function activeSessionForUser(User $user): ?MobileRepAttendanceSession
    {
        return MobileRepAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNull('sign_out_at')
            ->whereNull('suspended_at')
            ->orderByDesc('sign_in_at')
            ->first();
    }

    public function suspendedSessionForUser(User $user, ?Carbon $day = null): ?MobileRepAttendanceSession
    {
        $day ??= now();

        return MobileRepAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNull('sign_out_at')
            ->whereNotNull('suspended_at')
            ->whereDate('sign_in_at', $day->toDateString())
            ->orderByDesc('sign_in_at')
            ->first();
    }

    public function closedSessionForUserToday(User $user, ?Carbon $day = null): ?MobileRepAttendanceSession
    {
        $day ??= now();

        return MobileRepAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('sign_out_at')
            ->whereDate('sign_in_at', $day->toDateString())
            ->orderByDesc('sign_in_at')
            ->first();
    }

    public function hasClosedSessionToday(User $user, ?Carbon $day = null): bool
    {
        return $this->closedSessionForUserToday($user, $day) !== null;
    }

    /** @param  array<string, mixed>  $data */
    public function signIn(User $user, CapabilityGate $gate, array $data): MobileRepAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
        }

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

        return DB::transaction(function () use ($user, $data, $photo) {
            $photoPath = $this->storePhoto($photo, $user, 'sign-in');

            return MobileRepAttendanceSession::create(
                $this->newSessionAttributes($user, $data, $photoPath),
            );
        });
    }

    public function suspend(User $user, CapabilityGate $gate): MobileRepAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
        }

        $session = $this->activeSessionForUser($user);
        if (! $session) {
            throw new InvalidArgumentException('No active sign-in session found to suspend.');
        }

        return DB::transaction(function () use ($session) {
            $workSeconds = $this->workSeconds($session);

            $session->fill([
                'accumulated_work_seconds' => $workSeconds,
                'suspended_at' => now(),
            ]);
            $session->save();

            return $session->fresh();
        });
    }

    public function resume(User $user, CapabilityGate $gate): MobileRepAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
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
    public function signOut(User $user, CapabilityGate $gate, array $data): MobileRepAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
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

            $session->fill($this->signOutSessionAttributes($data, $photoPath, $workSeconds));
            $session->save();
            $this->syncToHr($session);

            return $session->fresh();
        });
    }

    /** Close open sessions that were not properly ended by the rep. */
    public function closeIdleSessions(?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $closed = 0;

        $sessions = MobileRepAttendanceSession::query()
            ->whereNull('sign_out_at')
            ->whereDate('sign_in_at', '<=', $asOf->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $this->closeAsIdle($session, $asOf);
            $this->syncToHr($session);
            $closed++;
        }

        return $closed;
    }

    public function workSeconds(MobileRepAttendanceSession $session, ?Carbon $until = null): int
    {
        if (! $session->sign_in_at) {
            return 0;
        }

        if ($session->isClosed()) {
            if ((int) $session->accumulated_work_seconds > 0) {
                return (int) $session->accumulated_work_seconds;
            }

            return max(0, $session->sign_in_at->diffInSeconds($session->sign_out_at));
        }

        if ($session->isSuspended()) {
            return max(0, (int) $session->accumulated_work_seconds);
        }

        $until ??= now();
        $anchor = $session->last_resumed_at ?? $session->sign_in_at;

        return max(0, (int) $session->accumulated_work_seconds)
            + max(0, $anchor->diffInSeconds($until));
    }

    public function suspendedSeconds(MobileRepAttendanceSession $session, ?Carbon $until = null): int
    {
        $total = max(0, (int) $session->accumulated_suspended_seconds);

        if ($session->isSuspended() && $session->suspended_at) {
            $until ??= now();
            $total += max(0, $session->suspended_at->diffInSeconds($until));
        }

        return $total;
    }

    public function formatWorkDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%d:%02d', $hours, $minutes);
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
                'total_work_hours' => 0,
                'total_work_label' => '0:00',
                'total_suspended_seconds' => 0,
                'total_suspended_hours' => 0,
                'total_suspended_label' => '0:00',
                'sign_in_allowed' => false,
                'requires_admin_reopen' => false,
                'blocked_message' => null,
            ];
        }

        $sessions = MobileRepAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereDate('sign_in_at', $day->toDateString())
            ->orderBy('sign_in_at')
            ->get();

        $totalSeconds = 0;
        $totalSuspendedSeconds = 0;
        $completed = 0;
        foreach ($sessions as $session) {
            $totalSeconds += $this->workSeconds($session);
            $totalSuspendedSeconds += $this->suspendedSeconds($session);
            if ($session->isClosed()) {
                $completed++;
            }
        }

        $openSession = $this->openSessionForUser($user);
        $requiresAdminReopen = $openSession === null && $completed > 0;
        $signInAllowed = $openSession === null && $completed === 0;

        return [
            'feature_enabled' => true,
            'session' => $openSession
                ? $this->serializeSession($openSession)
                : null,
            'sessions_today' => $sessions->count(),
            'completed_sessions_today' => $completed,
            'total_work_seconds' => $totalSeconds,
            'total_work_hours' => round($totalSeconds / 3600, 2),
            'total_work_label' => $this->formatWorkDuration($totalSeconds),
            'total_suspended_seconds' => $totalSuspendedSeconds,
            'total_suspended_hours' => round($totalSuspendedSeconds / 3600, 2),
            'total_suspended_label' => $this->formatWorkDuration($totalSuspendedSeconds),
            'sign_in_allowed' => $signInAllowed,
            'requires_admin_reopen' => $requiresAdminReopen,
            'blocked_message' => $requiresAdminReopen ? self::SIGN_IN_BLOCKED_MESSAGE : null,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    public function paginateForViewer(User $viewer, CapabilityGate $gate, array $filters = []): LengthAwarePaginator
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
        }

        $query = MobileRepAttendanceSession::query()
            ->with(['user:id,username,full_name,branch_id'])
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

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->whereHas('user', function (Builder $userQuery) use ($term) {
                $userQuery
                    ->where('username', 'like', $term)
                    ->orWhere('full_name', 'like', $term);
            });
        }

        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 25)));

        return $query->paginate($perPage);
    }

    public function findForViewer(User $viewer, int $sessionId): MobileRepAttendanceSession
    {
        $query = MobileRepAttendanceSession::query()
            ->with(['user:id,username,full_name,branch_id'])
            ->where('organization_id', (int) $viewer->organization_id)
            ->whereKey($sessionId);

        $this->access->scopeBranchIfLimited($query, $viewer);

        $session = $query->first();
        if (! $session) {
            throw new InvalidArgumentException('Attendance session not found.');
        }

        return $session;
    }

    /** @param  array<string, mixed>  $data */
    public function updateSession(
        User $editor,
        CapabilityGate $gate,
        int $sessionId,
        array $data,
    ): MobileRepAttendanceSession {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
        }

        $session = $this->findForViewer($editor, $sessionId);

        if (array_key_exists('sign_in_at', $data)) {
            $session->sign_in_at = Carbon::parse($data['sign_in_at']);
        }

        if (array_key_exists('sign_out_at', $data)) {
            $session->sign_out_at = $data['sign_out_at'] === null || $data['sign_out_at'] === ''
                ? null
                : Carbon::parse($data['sign_out_at']);

            if ($session->sign_out_at === null) {
                $session->close_reason = null;
            } elseif ($session->close_reason !== MobileRepAttendanceSession::CLOSE_REASON_SIGN_OUT) {
                $session->close_reason = MobileRepAttendanceSession::CLOSE_REASON_ADMIN;
            }
        }

        if ($session->sign_out_at && $session->sign_in_at && $session->sign_out_at->lt($session->sign_in_at)) {
            throw new InvalidArgumentException('Sign-out time must be after sign-in time.');
        }

        $session->save();
        if ($session->sign_out_at) {
            $this->syncToHr($session);
        }

        return $session->fresh(['user:id,username,full_name,branch_id']);
    }

    public function reopenSession(
        User $editor,
        CapabilityGate $gate,
        int $sessionId,
    ): MobileRepAttendanceSession {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
        }

        $session = $this->findForViewer($editor, $sessionId);

        if (! $session->isClosed()) {
            throw new InvalidArgumentException('Only closed sessions can be reopened.');
        }

        if (! $session->sign_in_at?->isSameDay(now())) {
            throw new InvalidArgumentException('Sessions can only be reopened on the same calendar day as sign-in.');
        }

        if ($this->openSessionForUser($session->user)) {
            throw new InvalidArgumentException('This rep already has an open session today.');
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

            return $session->fresh(['user:id,username,full_name,branch_id']);
        });
    }

    /** @return array<string, mixed> */
    public function serializeSession(
        MobileRepAttendanceSession $session,
        bool $includePhotos = false,
        ?array $hrLink = null,
    ): array {
        $workSeconds = $this->workSeconds($session);
        $suspendedSeconds = $this->suspendedSeconds($session);
        $user = $session->relationLoaded('user') ? $session->user : null;

        $payload = [
            'id' => $session->id,
            'user_id' => $session->user_id,
            'user_name' => $user?->full_name ?: $user?->username,
            'username' => $user?->username,
            'branch_id' => $session->branch_id,
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
            'status' => $this->sessionStatus($session),
            'close_reason' => $session->close_reason,
            'close_reason_label' => $this->closeReasonLabel($session->close_reason),
            'work_seconds' => $workSeconds,
            'work_hours' => round($workSeconds / 3600, 2),
            'work_label' => $this->formatWorkDuration($workSeconds),
            'suspended_seconds' => $suspendedSeconds,
            'suspended_hours' => round($suspendedSeconds / 3600, 2),
            'suspended_label' => $this->formatWorkDuration($suspendedSeconds),
            'reopened_at' => $session->reopened_at?->toIso8601String(),
            'can_reopen' => $this->canReopenSession($session),
            'source' => 'field_rep',
            'source_label' => 'Field rep',
        ];

        if ($hrLink !== null) {
            $payload['hr_link'] = $hrLink;
        }

        if ($includePhotos) {
            $payload['sign_in_photo_url'] = $this->signInPhotoFileUrl($session);
            $payload['sign_out_photo_url'] = $this->signOutPhotoFileUrl($session);
        }

        return $payload;
    }

    protected function sessionStatus(MobileRepAttendanceSession $session): string
    {
        if ($session->isClosed()) {
            return 'closed';
        }

        if ($session->isSuspended()) {
            return 'suspended';
        }

        return 'active';
    }

    protected function closeReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            MobileRepAttendanceSession::CLOSE_REASON_SIGN_OUT => 'User Logged Out',
            MobileRepAttendanceSession::CLOSE_REASON_IDLE_END_OF_DAY => 'System Signed out at Midnight',
            MobileRepAttendanceSession::CLOSE_REASON_ADMIN => 'Adjusted by admin',
            default => null,
        };
    }

    protected function closeAsIdle(MobileRepAttendanceSession $session, Carbon $asOf): void
    {
        $closeAt = $session->sign_in_at->isSameDay($asOf)
            ? $asOf->copy()->endOfDay()
            : $session->sign_in_at->copy()->endOfDay();

        if ($session->isSuspended()) {
            $this->accumulateSuspendedSegment($session, $closeAt);
        }

        $workSeconds = $session->isSuspended()
            ? max(0, (int) $session->accumulated_work_seconds)
            : $this->workSeconds($session, $closeAt);

        $session->fill([
            'accumulated_work_seconds' => $workSeconds,
            'suspended_at' => null,
            'sign_out_at' => $closeAt,
            'close_reason' => MobileRepAttendanceSession::CLOSE_REASON_IDLE_END_OF_DAY,
        ]);
        $session->save();
    }

    protected function canReopenSession(MobileRepAttendanceSession $session): bool
    {
        if (! $session->isClosed() || ! $session->sign_in_at?->isSameDay(now())) {
            return false;
        }

        return ! MobileRepAttendanceSession::query()
            ->where('user_id', $session->user_id)
            ->whereNull('sign_out_at')
            ->exists();
    }

    protected function accumulateSuspendedSegment(
        MobileRepAttendanceSession $session,
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

    protected function syncToHr(MobileRepAttendanceSession $session): void
    {
        try {
            app(FieldRepAttendanceHrSync::class)->syncSession($session);
        } catch (\Throwable $exception) {
            Log::warning('field rep HR attendance sync failed after session update', [
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    protected function signOutSessionAttributes(array $data, string $photoPath, int $workSeconds): array
    {
        $attributes = [
            'sign_out_at' => now(),
            'sign_out_latitude' => (float) $data['latitude'],
            'sign_out_longitude' => (float) $data['longitude'],
            'sign_out_address' => $this->trimAddress($data['address'] ?? null),
            'sign_out_photo_path' => $photoPath,
        ];

        if ($this->supportsWorkSessionColumns()) {
            $attributes['accumulated_work_seconds'] = $workSeconds;
            $attributes['suspended_at'] = null;
            $attributes['close_reason'] = MobileRepAttendanceSession::CLOSE_REASON_SIGN_OUT;
        }

        return $attributes;
    }

    protected function signInPhotoFileUrl(MobileRepAttendanceSession $session): ?string
    {
        if (! $this->photoPathExists($session->sign_in_photo_path)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/')
            ."/api/v1/sales/mobile-field-attendance/{$session->id}/sign-in-photo/file";
    }

    protected function signOutPhotoFileUrl(MobileRepAttendanceSession $session): ?string
    {
        if (! $this->photoPathExists($session->sign_out_photo_path)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/')
            ."/api/v1/sales/mobile-field-attendance/{$session->id}/sign-out-photo/file";
    }

    protected function photoPathExists(?string $path): bool
    {
        return is_string($path)
            && $path !== ''
            && Storage::disk('public')->exists($path);
    }

    protected function storePhoto(UploadedFile $photo, User $user, string $kind): string
    {
        try {
            $path = $photo->store(
                "mobile-attendance/{$user->organization_id}/{$user->id}/{$kind}",
                'public',
            );
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                'Unable to save the attendance photo. Ensure API storage is writable and linked (php artisan storage:link).',
                previous: $exception,
            );
        }

        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException('Unable to save the attendance photo.');
        }

        if (! Storage::disk('public')->exists($path)) {
            throw new InvalidArgumentException(
                'Attendance photo was not saved on the server. Ensure storage/app/public is writable and persisted.',
            );
        }

        return $path;
    }

    /** @param  array<string, mixed>  $data */
    protected function newSessionAttributes(User $user, array $data, string $photoPath): array
    {
        $attributes = [
            'organization_id' => (int) $user->organization_id,
            'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
            'user_id' => (int) $user->id,
            'sign_in_at' => now(),
            'sign_in_latitude' => (float) $data['latitude'],
            'sign_in_longitude' => (float) $data['longitude'],
            'sign_in_address' => $this->trimAddress($data['address'] ?? null),
            'sign_in_photo_path' => $photoPath,
            'device_identifier' => $this->trimDeviceId($data['device_identifier'] ?? null),
        ];

        if ($this->supportsWorkSessionColumns()) {
            $attributes['last_resumed_at'] = now();
        }

        return $attributes;
    }

    protected function supportsWorkSessionColumns(): bool
    {
        return Schema::hasTable('mobile_rep_attendance_sessions')
            && Schema::hasColumn('mobile_rep_attendance_sessions', 'last_resumed_at');
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
}
