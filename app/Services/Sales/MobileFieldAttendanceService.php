<?php

namespace App\Services\Sales;

use App\Models\MobileRepAttendanceSession;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\CapabilityGate;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MobileFieldAttendanceService
{
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

    /** @param  array<string, mixed>  $data */
    public function signIn(User $user, CapabilityGate $gate, array $data): MobileRepAttendanceSession
    {
        if (! $this->isEnabled($gate)) {
            throw new InvalidArgumentException('Field attendance is not enabled for this organization.');
        }

        if ($this->openSessionForUser($user)) {
            throw new InvalidArgumentException('You already have an active sign-in session.');
        }

        $photo = $data['photo'] ?? null;
        if (! $photo instanceof UploadedFile) {
            throw new InvalidArgumentException('A sign-in photo is required.');
        }

        return DB::transaction(function () use ($user, $data, $photo) {
            $photoPath = $this->storePhoto($photo, $user, 'sign-in');

            return MobileRepAttendanceSession::create([
                'organization_id' => (int) $user->organization_id,
                'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
                'user_id' => (int) $user->id,
                'sign_in_at' => now(),
                'sign_in_latitude' => (float) $data['latitude'],
                'sign_in_longitude' => (float) $data['longitude'],
                'sign_in_address' => $this->trimAddress($data['address'] ?? null),
                'sign_in_photo_path' => $photoPath,
                'device_identifier' => $this->trimDeviceId($data['device_identifier'] ?? null),
            ]);
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
            $photoPath = $this->storePhoto($photo, $user, 'sign-out');

            $session->fill([
                'sign_out_at' => now(),
                'sign_out_latitude' => (float) $data['latitude'],
                'sign_out_longitude' => (float) $data['longitude'],
                'sign_out_address' => $this->trimAddress($data['address'] ?? null),
                'sign_out_photo_path' => $photoPath,
            ]);
            $session->save();

            return $session->fresh();
        });
    }

    public function workSeconds(MobileRepAttendanceSession $session, ?Carbon $until = null): int
    {
        if (! $session->sign_in_at) {
            return 0;
        }

        $end = $session->sign_out_at ?? $until ?? now();

        return max(0, $session->sign_in_at->diffInSeconds($end));
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
            ];
        }

        $sessions = MobileRepAttendanceSession::query()
            ->where('user_id', $user->id)
            ->whereDate('sign_in_at', $day->toDateString())
            ->orderBy('sign_in_at')
            ->get();

        $totalSeconds = 0;
        $completed = 0;
        foreach ($sessions as $session) {
            $totalSeconds += $this->workSeconds($session);
            if (! $session->isOpen()) {
                $completed++;
            }
        }

        $openSession = $this->openSessionForUser($user);

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
        }

        if ($session->sign_out_at && $session->sign_in_at && $session->sign_out_at->lt($session->sign_in_at)) {
            throw new InvalidArgumentException('Sign-out time must be after sign-in time.');
        }

        $session->save();

        return $session->fresh(['user:id,username,full_name,branch_id']);
    }

    /** @return array<string, mixed> */
    public function serializeSession(MobileRepAttendanceSession $session, bool $includePhotos = false): array
    {
        $workSeconds = $this->workSeconds($session);
        $user = $session->relationLoaded('user') ? $session->user : null;

        $payload = [
            'id' => $session->id,
            'user_id' => $session->user_id,
            'user_name' => $user?->full_name ?: $user?->username,
            'username' => $user?->username,
            'branch_id' => $session->branch_id,
            'sign_in_at' => $session->sign_in_at?->toIso8601String(),
            'sign_out_at' => $session->sign_out_at?->toIso8601String(),
            'sign_in_latitude' => $session->sign_in_latitude,
            'sign_in_longitude' => $session->sign_in_longitude,
            'sign_out_latitude' => $session->sign_out_latitude,
            'sign_out_longitude' => $session->sign_out_longitude,
            'sign_in_address' => $session->sign_in_address,
            'sign_out_address' => $session->sign_out_address,
            'is_open' => $session->isOpen(),
            'work_seconds' => $workSeconds,
            'work_hours' => round($workSeconds / 3600, 2),
            'work_label' => $this->formatWorkDuration($workSeconds),
        ];

        if ($includePhotos) {
            $payload['sign_in_photo_url'] = $this->photoUrl($session->sign_in_photo_path);
            $payload['sign_out_photo_url'] = $this->photoUrl($session->sign_out_photo_path);
        }

        return $payload;
    }

    protected function photoUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return url('storage/'.$path);
    }

    protected function storePhoto(UploadedFile $photo, User $user, string $kind): string
    {
        return $photo->store(
            "mobile-attendance/{$user->organization_id}/{$user->id}/{$kind}",
            'public',
        );
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
