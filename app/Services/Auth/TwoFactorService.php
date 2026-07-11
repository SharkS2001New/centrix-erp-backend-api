<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Platform\PlatformMailSettingsResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorService
{
    public const METHOD_EMAIL = 'email';

    public const METHOD_TOTP = 'totp';

    public function __construct(
        protected TotpService $totp,
        protected EmailVerificationService $emailVerification,
    ) {}

    public function isEnabled(User $user): bool
    {
        return (bool) $user->two_factor_enabled
            && in_array($user->two_factor_method, [self::METHOD_EMAIL, self::METHOD_TOTP], true)
            && $user->two_factor_confirmed_at;
    }

    public function statusForUser(User $user): array
    {
        $emailStatus = $this->emailVerification->statusForUser($user);

        return [
            'enabled' => $this->isEnabled($user),
            'method' => $this->isEnabled($user) ? $user->two_factor_method : null,
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'email' => $emailStatus['email'],
            'has_email' => $emailStatus['has_email'],
            'email_verified' => $emailStatus['email_verified'],
            'allowed_methods' => [self::METHOD_EMAIL, self::METHOD_TOTP],
        ];
    }

    /**
     * @return array{mfa_required: bool, code: string, challenge_token: string, method: string, email_hint: ?string, expires_in: int}
     */
    public function startLoginChallenge(
        TenantAccount $account,
        string $clientId,
        bool $forceLogout,
        string $loginChannel,
    ): array {
        $user = $account->effectiveUser();
        if (! $this->isEnabled($user)) {
            throw ValidationException::withMessages([
                'username' => ['Two-factor authentication is not enabled for this account.'],
            ]);
        }

        $token = Str::random(64);
        $payload = [
            'user_id' => (int) $user->id,
            'organization_id' => (int) $account->organization->id,
            'canonical_user_id' => (int) $account->canonicalUserId(),
            'client_id' => $clientId,
            'force_logout' => $forceLogout,
            'login_channel' => $loginChannel,
            'method' => $user->two_factor_method,
        ];

            if ($user->two_factor_method === self::METHOD_EMAIL) {
                if (! PlatformMailSettingsResolver::canDeliverAuthMail()) {
                    throw ValidationException::withMessages([
                        'email' => [
                            'Platform outbound email is disabled. Enable it under Settings → Email delivery before using email two-factor authentication.',
                        ],
                    ]);
                }
                $code = (string) random_int(100000, 999999);
                $payload['email_code_hash'] = Hash::make($code);
                $this->sendEmailCode($user, $code, 'login');
            }

        Cache::put($this->challengeKey($token), $payload, now()->addMinutes(10));

        return [
            'mfa_required' => true,
            'code' => 'mfa_required',
            'challenge_token' => $token,
            'method' => $user->two_factor_method,
            'email_hint' => $this->emailHint($user->email),
            'expires_in' => 600,
        ];
    }

    /**
     * @return array{user_id: int, organization_id: int, client_id: string, force_logout: bool, login_channel: string}
     */
    public function verifyLoginChallenge(string $challengeToken, string $code): array
    {
        $key = $this->challengeKey($challengeToken);
        $payload = Cache::get($key);
        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'code' => ['This verification challenge has expired. Sign in again.'],
            ]);
        }

        $user = User::query()->find($payload['user_id'] ?? 0);
        if (! $user || ! $this->isEnabled($user)) {
            Cache::forget($key);
            throw ValidationException::withMessages([
                'code' => ['Two-factor authentication is no longer available for this account.'],
            ]);
        }

        if (! $this->verifyCodeForUser($user, $code, $payload)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        Cache::forget($key);

        return [
            'user_id' => (int) ($payload['canonical_user_id'] ?? $payload['user_id']),
            'organization_id' => (int) $payload['organization_id'],
            'client_id' => (string) $payload['client_id'],
            'force_logout' => (bool) ($payload['force_logout'] ?? false),
            'login_channel' => (string) ($payload['login_channel'] ?? 'backoffice'),
        ];
    }

    public function resendLoginEmailCode(string $challengeToken): array
    {
        $key = $this->challengeKey($challengeToken);
        $payload = Cache::get($key);
        if (! is_array($payload) || ($payload['method'] ?? '') !== self::METHOD_EMAIL) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This verification challenge has expired. Sign in again.'],
            ]);
        }

        $user = User::query()->find($payload['user_id'] ?? 0);
        if (! $user) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This verification challenge has expired. Sign in again.'],
            ]);
        }

        $code = (string) random_int(100000, 999999);
        $payload['email_code_hash'] = Hash::make($code);
        Cache::put($key, $payload, now()->addMinutes(10));
        if (! PlatformMailSettingsResolver::canDeliverAuthMail()) {
            throw ValidationException::withMessages([
                'email' => [
                    'Platform outbound email is disabled. Enable it under Settings → Email delivery.',
                ],
            ]);
        }
        $this->sendEmailCode($user, $code, 'login');

        return [
            'ok' => true,
            'email_hint' => $this->emailHint($user->email),
            'expires_in' => 600,
        ];
    }

    public function beginEmailSetup(User $user): array
    {
        $this->assertMethodAllowed(self::METHOD_EMAIL);
        if (! filter_var((string) $user->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['Add a valid email on your profile before enabling email 2FA.'],
            ]);
        }
        if (! $this->emailVerification->hasVerifiedEmail($user)) {
            throw ValidationException::withMessages([
                'email' => ['Verify your email address on your profile before enabling email 2FA.'],
            ]);
        }
        if (! PlatformMailSettingsResolver::canDeliverAuthMail()) {
            throw ValidationException::withMessages([
                'email' => [
                    'Platform outbound email is disabled. Enable it under Settings → Email delivery before enabling email 2FA.',
                ],
            ]);
        }

        $code = (string) random_int(100000, 999999);
        Cache::put($this->setupKey($user->id, self::METHOD_EMAIL), [
            'code_hash' => Hash::make($code),
        ], now()->addMinutes(10));

        $this->sendEmailCode($user, $code, 'setup');

        return [
            'ok' => true,
            'method' => self::METHOD_EMAIL,
            'email_hint' => $this->emailHint($user->email),
            'expires_in' => 600,
        ];
    }

    public function confirmEmailSetup(User $user, string $code): User
    {
        $cached = Cache::get($this->setupKey($user->id, self::METHOD_EMAIL));
        if (! is_array($cached) || empty($cached['code_hash'])) {
            throw ValidationException::withMessages([
                'code' => ['Setup code expired. Request a new one.'],
            ]);
        }
        if (! Hash::check(trim($code), $cached['code_hash'])) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_method' => self::METHOD_EMAIL,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => now(),
        ])->save();

        Cache::forget($this->setupKey($user->id, self::METHOD_EMAIL));

        return $user->fresh();
    }

    public function beginTotpSetup(User $user): array
    {
        $this->assertMethodAllowed(self::METHOD_TOTP);
        $secret = $this->totp->generateSecret();
        Cache::put($this->setupKey($user->id, self::METHOD_TOTP), [
            'secret' => $secret,
        ], now()->addMinutes(15));

        $account = $user->email ?: $user->username;
        $issuer = $user->organization?->org_name ?: 'Centrix ERP';

        return [
            'method' => self::METHOD_TOTP,
            'secret' => $secret,
            'otpauth_url' => $this->totp->otpauthUrl($secret, (string) $account, $issuer),
            'expires_in' => 900,
        ];
    }

    public function confirmTotpSetup(User $user, string $code): User
    {
        $cached = Cache::get($this->setupKey($user->id, self::METHOD_TOTP));
        $secret = is_array($cached) ? (string) ($cached['secret'] ?? '') : '';
        if ($secret === '') {
            throw ValidationException::withMessages([
                'code' => ['Authenticator setup expired. Start again.'],
            ]);
        }
        if (! $this->totp->verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid authenticator code.'],
            ]);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_method' => self::METHOD_TOTP,
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();

        Cache::forget($this->setupKey($user->id, self::METHOD_TOTP));

        return $user->fresh();
    }

    public function disable(User $user, string $password): User
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Current password is incorrect.'],
            ]);
        }

        return $this->forceDisable($user);
    }

    /**
     * Clear 2FA without password (e.g. email address changed, or admin unlock).
     */
    public function forceDisable(User $user): User
    {
        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $user->fresh();
    }

    /** @param  array<string, mixed>  $challengePayload */
    protected function verifyCodeForUser(User $user, string $code, array $challengePayload): bool
    {
        $method = $user->two_factor_method;
        if ($method === self::METHOD_EMAIL) {
            $hash = (string) ($challengePayload['email_code_hash'] ?? '');

            return $hash !== '' && Hash::check(trim($code), $hash);
        }

        if ($method === self::METHOD_TOTP) {
            $secret = $this->decryptSecret($user);

            return $secret !== null && $this->totp->verify($secret, $code);
        }

        return false;
    }

    protected function decryptSecret(User $user): ?string
    {
        $encrypted = (string) ($user->two_factor_secret ?? '');
        if ($encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function sendEmailCode(User $user, string $code, string $purpose): void
    {
        $to = (string) $user->email;
        $subject = $purpose === 'setup'
            ? 'Centrix ERP — confirm two-factor authentication'
            : 'Centrix ERP — your sign-in verification code';
        $body = "Hello {$user->full_name},\n\n"
            ."Your Centrix verification code is: {$code}\n\n"
            ."This code expires in 10 minutes. If you did not request it, ignore this email.\n\n"
            ."This is an automated message — please do not reply.\n";

        try {
            PlatformMailSettingsResolver::sendRaw($to, $subject, $body, $user, [
                'kind' => 'two_factor',
                'purpose' => $purpose,
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
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'email' => ['Could not send the verification email via platform email. Check Platform → Email settings.'],
            ]);
        }
    }

    protected function assertMethodAllowed(string $method): void
    {
        if (! in_array($method, [self::METHOD_EMAIL, self::METHOD_TOTP], true)) {
            throw ValidationException::withMessages([
                'method' => ['Unsupported two-factor method.'],
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

    protected function challengeKey(string $token): string
    {
        return 'auth:2fa:challenge:'.$token;
    }

    protected function setupKey(int $userId, string $method): string
    {
        return "auth:2fa:setup:{$userId}:{$method}";
    }
}
