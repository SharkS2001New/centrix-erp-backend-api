<?php

namespace App\Services\Auth;

use App\Models\PersonalAccessToken;
use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\IndustryRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PinLoginService
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 6;

    protected const MAX_ATTEMPTS = 5;

    protected const LOCK_SECONDS = 300;

    public function __construct(
        protected AuthSessionService $sessions,
        protected UserAccessService $access,
    ) {}

    public function normalize(?string $pin): string
    {
        return preg_replace('/\D+/', '', (string) $pin) ?? '';
    }

    public function assertValidFormat(string $pin, string $field = 'pin'): void
    {
        $pin = $this->normalize($pin);
        $len = strlen($pin);
        if ($len < self::MIN_LENGTH || $len > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                $field => ['PIN must be 4 to 6 digits.'],
            ]);
        }
    }

    public function hash(string $pin): string
    {
        $this->assertValidFormat($pin);

        return Hash::make($this->normalize($pin));
    }

    public function assertHospitalityPinFeatures(?User $user): void
    {
        $org = $user?->organization;
        if (! $org && $user?->organization_id) {
            $org = Organization::query()->find($user->organization_id);
        }
        if (! $org || ! IndustryRegistry::isHospitality($org->deployment_profile)) {
            throw ValidationException::withMessages([
                'pin' => ['PIN sign-in is only available for Hotel & Hospitality.'],
            ]);
        }
    }

    public function userHasPin(User $user): bool
    {
        return filled($user->getAttributes()['login_pin'] ?? null);
    }

    public function assignPin(User $user, ?string $pin): void
    {
        if ($pin === null || trim((string) $pin) === '') {
            $user->forceFill(['login_pin' => null])->save();

            return;
        }

        $user->forceFill(['login_pin' => $this->hash($pin)])->save();
    }

    /**
     * @return list<array{id: int, full_name: string|null, username: string|null, has_login_pin: bool}>
     */
    public function listOperators(User $actor): array
    {
        $query = User::query()
            ->where('organization_id', $actor->organization_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->whereNotNull('login_pin')
            ->where('login_pin', '!=', '')
            ->where(function ($inner) {
                $inner->where('is_super_admin', false)->orWhereNull('is_super_admin');
            });

        $this->access->scopeBranchIfLimited($query, $actor);

        return $query
            ->orderBy('full_name')
            ->orderBy('username')
            ->get(['id', 'full_name', 'username'])
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'has_login_pin' => true,
            ])
            ->values()
            ->all();
    }

    public function unlockCurrent(User $user, string $pin): void
    {
        $this->assertPinMatches($user, $pin, 'pin');
    }

    /**
     * @return array{token: string, user: User, organization: \App\Models\Organization, memberships: array}
     */
    public function switchOperator(
        User $current,
        int $targetUserId,
        string $pin,
        string $clientId,
    ): array {
        $target = User::query()
            ->where('id', $targetUserId)
            ->where('organization_id', $current->organization_id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        $allowedIds = collect($this->listOperators($current))->pluck('id')->all();
        if (! $target || ! in_array((int) $target->id, $allowedIds, true)) {
            throw ValidationException::withMessages([
                'user_id' => ['That user is not available on this terminal.'],
            ]);
        }

        $this->assertPinMatches($target, $pin, 'pin');

        if ((int) $target->id === (int) $current->id) {
            $this->touchPersistedAccessToken($current->currentAccessToken());

            return [
                'token' => null,
                'user' => $current->fresh() ?? $current,
                'organization' => $current->organization,
                'memberships' => [],
                'same_user' => true,
            ];
        }

        $currentToken = $current->currentAccessToken();
        $loginChannel = $this->tokenLoginChannel($currentToken);
        $workspaceId = $this->tokenWorkspaceId($currentToken);

        $result = $this->sessions->issueOperatorSession(
            $target,
            $clientId,
            $loginChannel,
            $workspaceId,
        );

        $this->forgetPersistedAccessToken($currentToken);

        $result['operator_switch'] = true;

        return $result;
    }

    public function touchPersistedAccessToken(mixed $token): void
    {
        if (! $this->isPersistedAccessToken($token)) {
            return;
        }

        $token->last_used_at = now();
        $token->save();
    }

    public function forgetPersistedAccessToken(mixed $token): void
    {
        if ($this->isPersistedAccessToken($token)) {
            $token->delete();
        }
    }

    public function isPersistedAccessToken(mixed $token): bool
    {
        if (! $token instanceof PersonalAccessToken) {
            return false;
        }

        try {
            return (bool) $token->exists;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function tokenLoginChannel(mixed $token): string
    {
        try {
            $channel = is_object($token) ? ($token->login_channel ?? null) : null;
        } catch (\Throwable) {
            $channel = null;
        }

        return is_string($channel) && $channel !== ''
            ? $channel
            : UserLoginChannelService::BACKOFFICE;
    }

    protected function tokenWorkspaceId(mixed $token): ?string
    {
        try {
            $workspaceId = is_object($token) ? ($token->active_workspace_id ?? null) : null;
        } catch (\Throwable) {
            return null;
        }

        return is_string($workspaceId) && $workspaceId !== '' ? $workspaceId : null;
    }

    public function assertPinMatches(User $user, string $pin, string $field = 'pin'): void
    {
        $this->assertNotLocked($user, $field);
        $this->assertValidFormat($pin, $field);

        if (! $this->userHasPin($user) || ! Hash::check($this->normalize($pin), (string) $user->login_pin)) {
            $this->recordFailure($user);
            throw ValidationException::withMessages([
                $field => ['Incorrect PIN.'],
            ]);
        }

        $this->clearFailures($user);
    }

    protected function assertNotLocked(User $user, string $field): void
    {
        $until = Cache::get($this->lockCacheKey($user));
        if (! $until) {
            return;
        }

        $seconds = max(1, (int) $until - time());
        throw ValidationException::withMessages([
            $field => ["Too many incorrect PINs. Try again in {$seconds} seconds."],
        ]);
    }

    protected function recordFailure(User $user): void
    {
        $key = $this->failCacheKey($user);
        $attempts = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $attempts, self::LOCK_SECONDS);
        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::put($this->lockCacheKey($user), time() + self::LOCK_SECONDS, self::LOCK_SECONDS);
            Cache::forget($key);
        }
    }

    protected function clearFailures(User $user): void
    {
        Cache::forget($this->failCacheKey($user));
        Cache::forget($this->lockCacheKey($user));
    }

    protected function failCacheKey(User $user): string
    {
        return 'pin-login:fail:'.$user->organization_id.':'.$user->id;
    }

    protected function lockCacheKey(User $user): string
    {
        return 'pin-login:lock:'.$user->organization_id.':'.$user->id;
    }
}
