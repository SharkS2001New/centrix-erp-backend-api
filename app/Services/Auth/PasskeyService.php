<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\WebAuthnCredential;
use Cose\Algorithms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class PasskeyService
{
    private ?SerializerInterface $serializer = null;

    public function listForUser(User $user): array
    {
        return WebAuthnCredential::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WebAuthnCredential $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'backup_eligible' => $c->backup_eligible,
                'backup_status' => $c->backup_status,
                'last_used_at' => $c->last_used_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function userHasPasskeys(User $user): bool
    {
        return WebAuthnCredential::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @return array{options: array<string, mixed>, challenge_token: string}
     */
    public function beginRegistration(User $user, ?string $deviceName = null): array
    {
        $rp = $this->rpEntity();
        $userEntity = PublicKeyCredentialUserEntity::create(
            (string) ($user->username ?: $user->email ?: 'user-'.$user->id),
            $this->userHandleFor($user),
            (string) ($user->full_name ?: $user->username ?: 'Centrix user'),
        );

        $exclude = WebAuthnCredential::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (WebAuthnCredential $c) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                WebAuthnCredential::base64UrlDecode($c->credential_id),
                is_array($c->transports) ? $c->transports : [],
            ))
            ->all();

        $challenge = random_bytes(32);
        $options = PublicKeyCredentialCreationOptions::create(
            $rp,
            $userEntity,
            $challenge,
            [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            (int) config('webauthn.timeout_ms', 60_000),
        );

        $token = Str::random(64);
        Cache::put($this->registerCacheKey($token), [
            'user_id' => (int) $user->id,
            'options' => $this->serialize($options),
            'device_name' => $deviceName,
        ], now()->addMinutes(10));

        return [
            'challenge_token' => $token,
            'options' => $this->normalizeForBrowser($options),
        ];
    }

    public function completeRegistration(User $user, string $challengeToken, array $credentialJson, ?string $name = null): WebAuthnCredential
    {
        $cached = Cache::pull($this->registerCacheKey($challengeToken));
        if (! is_array($cached) || (int) ($cached['user_id'] ?? 0) !== (int) $user->id) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey registration expired. Try again.'],
            ]);
        }

        /** @var PublicKeyCredentialCreationOptions $options */
        $options = $this->deserialize($cached['options'], PublicKeyCredentialCreationOptions::class);
        $publicKeyCredential = $this->loadPublicKeyCredential($credentialJson);

        if (! $publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey registration response.'],
            ]);
        }

        try {
            $record = $this->attestationValidator()->check(
                $publicKeyCredential->response,
                $options,
                $this->host(),
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'credential' => ['Could not verify this passkey: '.$e->getMessage()],
            ]);
        }

        $credentialId = WebAuthnCredential::base64UrlEncode($record->publicKeyCredentialId);
        if (WebAuthnCredential::query()->where('credential_id', $credentialId)->exists()) {
            throw ValidationException::withMessages([
                'credential' => ['This passkey is already registered.'],
            ]);
        }

        $label = trim((string) ($name ?: $cached['device_name'] ?? '')) ?: 'Passkey';

        return WebAuthnCredential::storeFromCredentialRecord((int) $user->id, $record, $label);
    }

    public function rename(User $user, int $credentialId, string $name): WebAuthnCredential
    {
        $credential = WebAuthnCredential::query()
            ->where('user_id', $user->id)
            ->where('id', $credentialId)
            ->firstOrFail();
        $credential->update(['name' => trim($name) ?: 'Passkey']);

        return $credential->fresh();
    }

    public function delete(User $user, int $credentialId): void
    {
        WebAuthnCredential::query()
            ->where('user_id', $user->id)
            ->where('id', $credentialId)
            ->delete();
    }

    /**
     * Whether a passkey login is available for this org + username (no ceremony started).
     *
     * @return array{available: bool}
     */
    public function loginAvailability(?string $username = null, ?string $companyCode = null): array
    {
        $user = $this->resolveLoginUser($username, $companyCode);

        return [
            'available' => $user !== null && $this->userHasPasskeys($user),
        ];
    }

    /**
     * Org-scoped passkey options. Requires company_code + username when probing a tenant account.
     * Empty allowCredentials is never returned for a resolved user without passkeys (avoids
     * discoverable “any account” prompts for the wrong org).
     *
     * @return array{has_credentials: bool, options?: array<string, mixed>, challenge_token?: string}
     */
    public function beginLogin(?string $username = null, ?string $companyCode = null): array
    {
        $user = $this->resolveLoginUser($username, $companyCode);
        if (! $user) {
            return ['has_credentials' => false];
        }

        $allowCredentials = WebAuthnCredential::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (WebAuthnCredential $c) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                WebAuthnCredential::base64UrlDecode($c->credential_id),
                is_array($c->transports) ? $c->transports : [],
            ))
            ->all();

        if ($allowCredentials === []) {
            return ['has_credentials' => false];
        }

        $challenge = random_bytes(32);
        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            (string) config('webauthn.rp_id'),
            $allowCredentials,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            (int) config('webauthn.timeout_ms', 60_000),
        );

        $token = Str::random(64);
        Cache::put($this->loginCacheKey($token), [
            'options' => $this->serialize($options),
            'hint_user_id' => (int) $user->id,
        ], now()->addMinutes(10));

        return [
            'has_credentials' => true,
            'challenge_token' => $token,
            'options' => $this->normalizeForBrowser($options),
        ];
    }

    /**
     * Authenticated unlock: assert a passkey for the current user without issuing a new session.
     *
     * @return array{options: array<string, mixed>, challenge_token: string}
     */
    public function beginUnlockAssertion(User $user): array
    {
        $allowCredentials = WebAuthnCredential::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (WebAuthnCredential $c) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                WebAuthnCredential::base64UrlDecode($c->credential_id),
                is_array($c->transports) ? $c->transports : [],
            ))
            ->all();

        if ($allowCredentials === []) {
            throw ValidationException::withMessages([
                'credential' => ['No passkeys are registered for this account.'],
            ]);
        }

        $challenge = random_bytes(32);
        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            (string) config('webauthn.rp_id'),
            $allowCredentials,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            (int) config('webauthn.timeout_ms', 60_000),
        );

        $token = Str::random(64);
        Cache::put($this->unlockCacheKey($token), [
            'options' => $this->serialize($options),
            'user_id' => (int) $user->id,
        ], now()->addMinutes(10));

        return [
            'challenge_token' => $token,
            'options' => $this->normalizeForBrowser($options),
        ];
    }

    public function completeUnlockAssertion(User $user, string $challengeToken, array $credentialJson): void
    {
        $cached = Cache::pull($this->unlockCacheKey($challengeToken));
        if (! is_array($cached) || (int) ($cached['user_id'] ?? 0) !== (int) $user->id) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey unlock expired. Try again.'],
            ]);
        }

        $this->completeLoginWithCachedOptions(
            (string) $cached['options'],
            $credentialJson,
            (int) $user->id,
        );
    }

    protected function resolveLoginUser(?string $username, ?string $companyCode): ?User
    {
        $username = trim((string) $username);
        $companyCode = strtoupper(trim((string) $companyCode));
        if ($username === '' || $companyCode === '') {
            return null;
        }

        $org = \App\Models\Organization::findByCompanyCodeIdentifier($companyCode);
        if (! $org) {
            return null;
        }

        $account = app(TenantAccountResolver::class)->resolve($org, $username);

        return $account?->effectiveUser();
    }

    /**
     * @return array{user: User, credential: WebAuthnCredential}
     */
    public function completeLogin(string $challengeToken, array $credentialJson): array
    {
        $cached = Cache::pull($this->loginCacheKey($challengeToken));
        if (! is_array($cached)) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey sign-in expired. Try again.'],
            ]);
        }

        /** @var PublicKeyCredentialRequestOptions $options */
        $options = $this->deserialize($cached['options'], PublicKeyCredentialRequestOptions::class);
        $publicKeyCredential = $this->loadPublicKeyCredential($credentialJson);

        if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey assertion.'],
            ]);
        }

        $credentialId = WebAuthnCredential::base64UrlEncode($publicKeyCredential->rawId);
        $stored = WebAuthnCredential::query()->where('credential_id', $credentialId)->first();
        if (! $stored) {
            throw ValidationException::withMessages([
                'credential' => ['Unrecognized passkey.'],
            ]);
        }

        $record = $stored->toCredentialRecord();

        try {
            $updated = $this->assertionValidator()->check(
                $record,
                $publicKeyCredential->response,
                $options,
                $this->host(),
                $record->userHandle,
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey verification failed: '.$e->getMessage()],
            ]);
        }

        $stored->forceFill([
            'counter' => $updated->counter,
            'backup_eligible' => $updated->backupEligible,
            'backup_status' => $updated->backupStatus,
            'last_used_at' => now(),
        ])->save();

        $user = User::query()->find($stored->user_id);
        if (! $user || ! $user->is_active || $user->deleted_at) {
            throw ValidationException::withMessages([
                'credential' => ['This account is not available.'],
            ]);
        }

        return [
            'user' => $user,
            'credential' => $stored,
        ];
    }

    /**
     * Options for using a passkey as the second factor after password.
     *
     * @return array{options: array<string, mixed>, challenge_token: string}
     */
    public function beginTwoFactorAssertion(string $mfaChallengeToken): array
    {
        $payload = Cache::get($this->mfaChallengeKey($mfaChallengeToken));
        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This verification challenge has expired. Sign in again.'],
            ]);
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $allowCredentials = WebAuthnCredential::query()
            ->where('user_id', $userId)
            ->get()
            ->map(fn (WebAuthnCredential $c) => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                WebAuthnCredential::base64UrlDecode($c->credential_id),
                is_array($c->transports) ? $c->transports : [],
            ))
            ->all();

        if ($allowCredentials === []) {
            throw ValidationException::withMessages([
                'challenge_token' => ['No passkeys are registered for this account.'],
            ]);
        }

        $challenge = random_bytes(32);
        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            (string) config('webauthn.rp_id'),
            $allowCredentials,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            (int) config('webauthn.timeout_ms', 60_000),
        );

        $token = Str::random(64);
        Cache::put($this->mfaPasskeyCacheKey($token), [
            'mfa_challenge_token' => $mfaChallengeToken,
            'options' => $this->serialize($options),
            'user_id' => $userId,
        ], now()->addMinutes(10));

        return [
            'challenge_token' => $token,
            'options' => $this->normalizeForBrowser($options),
        ];
    }

    /**
     * @return array{user_id: int, organization_id: int, client_id: string, force_logout: bool, login_channel: string}
     */
    public function completeTwoFactorAssertion(string $passkeyChallengeToken, array $credentialJson): array
    {
        $cached = Cache::pull($this->mfaPasskeyCacheKey($passkeyChallengeToken));
        if (! is_array($cached)) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey verification expired. Try again.'],
            ]);
        }

        $mfaKey = $this->mfaChallengeKey((string) $cached['mfa_challenge_token']);
        $mfaPayload = Cache::get($mfaKey);
        if (! is_array($mfaPayload)) {
            throw ValidationException::withMessages([
                'credential' => ['This verification challenge has expired. Sign in again.'],
            ]);
        }

        $result = $this->completeLoginWithCachedOptions(
            (string) $cached['options'],
            $credentialJson,
            (int) $cached['user_id'],
        );

        Cache::forget($mfaKey);

        return [
            'user_id' => (int) ($mfaPayload['canonical_user_id'] ?? $mfaPayload['user_id']),
            'organization_id' => (int) $mfaPayload['organization_id'],
            'client_id' => (string) $mfaPayload['client_id'],
            'force_logout' => (bool) ($mfaPayload['force_logout'] ?? false),
            'login_channel' => (string) ($mfaPayload['login_channel'] ?? 'backoffice'),
            'verified_user_id' => (int) $result['user']->id,
        ];
    }

    /**
     * @return array{user: User, credential: WebAuthnCredential}
     */
    protected function completeLoginWithCachedOptions(string $serializedOptions, array $credentialJson, ?int $expectedUserId = null): array
    {
        /** @var PublicKeyCredentialRequestOptions $options */
        $options = $this->deserialize($serializedOptions, PublicKeyCredentialRequestOptions::class);
        $publicKeyCredential = $this->loadPublicKeyCredential($credentialJson);

        if (! $publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw ValidationException::withMessages([
                'credential' => ['Invalid passkey assertion.'],
            ]);
        }

        $credentialId = WebAuthnCredential::base64UrlEncode($publicKeyCredential->rawId);
        $stored = WebAuthnCredential::query()->where('credential_id', $credentialId)->first();
        if (! $stored) {
            throw ValidationException::withMessages([
                'credential' => ['Unrecognized passkey.'],
            ]);
        }

        if ($expectedUserId !== null && (int) $stored->user_id !== $expectedUserId) {
            throw ValidationException::withMessages([
                'credential' => ['This passkey does not belong to the signed-in account.'],
            ]);
        }

        $record = $stored->toCredentialRecord();

        try {
            $updated = $this->assertionValidator()->check(
                $record,
                $publicKeyCredential->response,
                $options,
                $this->host(),
                $record->userHandle,
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'credential' => ['Passkey verification failed: '.$e->getMessage()],
            ]);
        }

        $stored->forceFill([
            'counter' => $updated->counter,
            'backup_eligible' => $updated->backupEligible,
            'backup_status' => $updated->backupStatus,
            'last_used_at' => now(),
        ])->save();

        $user = User::query()->findOrFail($stored->user_id);

        return ['user' => $user, 'credential' => $stored];
    }

    protected function userHandleFor(User $user): string
    {
        return 'u:'.(string) $user->id;
    }

    protected function rpEntity(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create(
            (string) config('webauthn.rp_name', 'Centrix ERP'),
            (string) config('webauthn.rp_id'),
        );
    }

    protected function host(): string
    {
        return (string) config('webauthn.rp_id');
    }

    protected function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins(array_values(config('webauthn.allowed_origins', [])), true);

        return AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());
    }

    protected function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins(array_values(config('webauthn.allowed_origins', [])), true);

        return AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());
    }

    protected function serializer(): SerializerInterface
    {
        if ($this->serializer === null) {
            $attestationManager = AttestationStatementSupportManager::create();
            $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();
        }

        return $this->serializer;
    }

    protected function serialize(object $value): string
    {
        return $this->serializer()->serialize($value, JsonEncoder::FORMAT);
    }

    /**
     * @template T of object
     * @param  class-string<T>  $type
     * @return T
     */
    protected function deserialize(string $json, string $type): object
    {
        return $this->serializer()->deserialize($json, $type, JsonEncoder::FORMAT);
    }

    /** @param  array<string, mixed>  $credentialJson */
    protected function loadPublicKeyCredential(array $credentialJson): PublicKeyCredential
    {
        return $this->serializer()->deserialize(
            json_encode($credentialJson, JSON_THROW_ON_ERROR),
            PublicKeyCredential::class,
            JsonEncoder::FORMAT,
        );
    }

    /** @return array<string, mixed> */
    protected function normalizeForBrowser(object $options): array
    {
        $json = $this->serialize($options);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    protected function registerCacheKey(string $token): string
    {
        return 'webauthn:register:'.$token;
    }

    protected function loginCacheKey(string $token): string
    {
        return 'webauthn:login:'.$token;
    }

    protected function unlockCacheKey(string $token): string
    {
        return 'webauthn:unlock:'.$token;
    }

    protected function mfaPasskeyCacheKey(string $token): string
    {
        return 'webauthn:mfa:'.$token;
    }

    protected function mfaChallengeKey(string $token): string
    {
        return 'auth:2fa:challenge:'.$token;
    }
}
