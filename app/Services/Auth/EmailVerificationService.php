<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Platform\PlatformMailSettingsResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    public function hasVerifiedEmail(User $user): bool
    {
        return filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) !== false
            && $user->email_verified_at !== null;
    }

    /** @return array{email: ?string, has_email: bool, email_verified: bool, verified_at: ?string} */
    public function statusForUser(User $user): array
    {
        $hasEmail = filter_var((string) $user->email, FILTER_VALIDATE_EMAIL) !== false;

        return [
            'email' => $user->email,
            'has_email' => $hasEmail,
            'email_verified' => $this->hasVerifiedEmail($user),
            'verified_at' => $user->email_verified_at?->toIso8601String(),
        ];
    }

    public function begin(User $user): array
    {
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['Save a valid email address on your profile before verifying it.'],
            ]);
        }

        if ($this->hasVerifiedEmail($user)) {
            return [
                'ok' => true,
                'already_verified' => true,
                'email_hint' => $this->emailHint($user->email),
                ...$this->statusForUser($user),
            ];
        }

        $code = (string) random_int(100000, 999999);
        Cache::put($this->cacheKey((int) $user->id), [
            'email' => strtolower(trim((string) $user->email)),
            'code_hash' => Hash::make($code),
        ], now()->addMinutes(10));

        $this->sendCode($user, $code);

        return [
            'ok' => true,
            'already_verified' => false,
            'email_hint' => $this->emailHint($user->email),
            'expires_in' => 600,
            ...$this->statusForUser($user),
        ];
    }

    public function confirm(User $user, string $code): User
    {
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['Save a valid email address on your profile before verifying it.'],
            ]);
        }

        $key = $this->cacheKey((int) $user->id);
        $cached = Cache::get($key);
        if (! is_array($cached) || empty($cached['code_hash'])) {
            throw ValidationException::withMessages([
                'code' => ['Verification code expired. Request a new one.'],
            ]);
        }

        $cachedEmail = strtolower(trim((string) ($cached['email'] ?? '')));
        $currentEmail = strtolower(trim((string) $user->email));
        if ($cachedEmail === '' || $cachedEmail !== $currentEmail) {
            Cache::forget($key);
            throw ValidationException::withMessages([
                'code' => ['Your email changed after this code was sent. Request a new verification code.'],
            ]);
        }

        if (! Hash::check(trim($code), $cached['code_hash'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        Cache::forget($key);

        return $user->fresh();
    }

    public function clearVerification(User $user): void
    {
        if ($user->email_verified_at === null) {
            return;
        }

        $user->forceFill([
            'email_verified_at' => null,
        ])->save();
    }

    protected function sendCode(User $user, string $code): void
    {
        $to = (string) $user->email;
        $subject = 'Centrix ERP — verify your email address';
        $body = "Hello {$user->full_name},\n\n"
            ."Your Centrix email verification code is: {$code}\n\n"
            ."This code expires in 10 minutes. If you did not request it, ignore this email.\n\n"
            ."This is an automated message — please do not reply.\n";

        try {
            PlatformMailSettingsResolver::sendRaw($to, $subject, $body, $user, [
                'kind' => 'email_verification',
                'no_reply' => true,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw ValidationException::withMessages([
                'email' => [
                    $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'Could not send the verification email. Ask a platform admin to configure Platform → Email.',
                ],
            ]);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'email' => ['Could not send the verification email via platform email. Check Platform → Email settings.'],
            ]);
        }
    }

    protected function emailHint(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, 1);

        return $visible.'***@'.$domain;
    }

    protected function cacheKey(int $userId): string
    {
        return "auth:email-verify:{$userId}";
    }
}
