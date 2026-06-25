<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class PasswordExpiryService
{
    /** @return array<string, mixed> */
    public function statusForUser(User $user): array
    {
        $settings = SecuritySettingsResolver::forOrganizationId(
            $user->organization_id ? (int) $user->organization_id : null,
        );

        $enabled = (bool) ($settings['password_expiry_enabled'] ?? false);
        $maxSkips = max(0, (int) ($settings['password_expiry_max_skips'] ?? 2));
        $expiryDays = max(1, (int) ($settings['password_expiry_days'] ?? 90));
        $skipCount = max(0, (int) ($user->password_expiry_skip_count ?? 0));

        if ((bool) $user->must_change_password) {
            return $this->payload(
                enabled: $enabled,
                expired: true,
                forced: true,
                skipCount: $skipCount,
                maxSkips: $maxSkips,
                expiryDays: $expiryDays,
                changedAt: $user->password_changed_at,
                expiresAt: null,
                reason: 'must_change_password',
            );
        }

        if (! $enabled) {
            return $this->payload(
                enabled: false,
                expired: false,
                forced: false,
                skipCount: $skipCount,
                maxSkips: $maxSkips,
                expiryDays: $expiryDays,
                changedAt: $user->password_changed_at,
                expiresAt: null,
            );
        }

        $changedAt = $user->password_changed_at ?? $user->created_at;
        if (! $changedAt instanceof CarbonInterface) {
            $changedAt = now();
        }

        $expiresAt = $changedAt->copy()->addDays($expiryDays);
        $expired = now()->greaterThanOrEqualTo($expiresAt);
        $skipsRemaining = max(0, $maxSkips - $skipCount);
        $forced = $expired && $skipCount >= $maxSkips;

        return $this->payload(
            enabled: true,
            expired: $expired,
            forced: $forced,
            skipCount: $skipCount,
            maxSkips: $maxSkips,
            expiryDays: $expiryDays,
            changedAt: $changedAt,
            expiresAt: $expiresAt,
            skipsRemaining: $skipsRemaining,
            reason: $forced ? 'expired_forced' : ($expired ? 'expired' : null),
        );
    }

    public function skipExpiryReminder(User $user): array
    {
        $status = $this->statusForUser($user);

        if (! ($status['enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'password' => ['Password expiry is not enabled for this organization.'],
            ]);
        }

        if (! ($status['expired'] ?? false)) {
            throw ValidationException::withMessages([
                'password' => ['Your password has not expired yet.'],
            ]);
        }

        if ($status['forced'] ?? false) {
            throw ValidationException::withMessages([
                'password' => ['You must change your password before continuing.'],
            ]);
        }

        $user->forceFill([
            'password_expiry_skip_count' => min(
                (int) ($status['max_skips'] ?? 0),
                (int) ($user->password_expiry_skip_count ?? 0) + 1,
            ),
        ])->save();

        return $this->statusForUser($user->fresh());
    }

    public function markPasswordChanged(User $user): void
    {
        $user->forceFill([
            'password_changed_at' => now(),
            'password_expiry_skip_count' => 0,
            'must_change_password' => false,
        ])->save();
    }

    /** @return array<string, mixed> */
    protected function payload(
        bool $enabled,
        bool $expired,
        bool $forced,
        int $skipCount,
        int $maxSkips,
        int $expiryDays,
        mixed $changedAt,
        mixed $expiresAt,
        ?int $skipsRemaining = null,
        ?string $reason = null,
    ): array {
        $skipsRemaining ??= max(0, $maxSkips - $skipCount);
        $daysUntilExpiry = null;
        if ($expiresAt instanceof CarbonInterface) {
            $daysUntilExpiry = (int) now()->startOfDay()->diffInDays($expiresAt->copy()->startOfDay(), false);
        }

        return [
            'enabled' => $enabled,
            'expired' => $expired,
            'forced' => $forced,
            'skip_count' => $skipCount,
            'max_skips' => $maxSkips,
            'skips_remaining' => $skipsRemaining,
            'expiry_days' => $expiryDays,
            'password_changed_at' => $changedAt instanceof CarbonInterface ? $changedAt->toIso8601String() : null,
            'expires_at' => $expiresAt instanceof CarbonInterface ? $expiresAt->toIso8601String() : null,
            'days_until_expiry' => $daysUntilExpiry,
            'reason' => $reason,
        ];
    }
}
