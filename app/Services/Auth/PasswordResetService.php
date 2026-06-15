<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserPasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public function __construct(
        protected TenantAccountResolver $resolver,
    ) {}

    /**
     * @return array{message: string, reset_url?: string}
     */
    public function requestReset(string $companyCode, string $username): array
    {
        $companyCode = strtoupper(trim($companyCode));
        $username = trim($username);
        $message = 'If the account exists, password reset instructions have been sent.';

        if ($companyCode === '' || $username === '') {
            return ['message' => $message];
        }

        $org = \App\Models\Organization::where('company_code', $companyCode)->first();
        if (! $org) {
            return ['message' => $message];
        }

        $account = $this->resolver->resolve($org, $username);
        if (! $account) {
            return ['message' => $message];
        }

        $plainToken = Str::random(64);

        UserPasswordReset::query()
            ->where('user_id', $account->authUser->id)
            ->where('organization_id', $org->id)
            ->delete();

        UserPasswordReset::create([
            'user_id' => $account->authUser->id,
            'organization_id' => $org->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHour(),
        ]);

        $resetUrl = config('erp.frontend_url').'/reset-password?token='.urlencode($plainToken).'&org='.urlencode($org->company_code);

        $this->dispatchResetNotification($account->authUser, $org, $resetUrl);

        $payload = ['message' => $message];
        if (config('app.debug')) {
            $payload['reset_url'] = $resetUrl;
        }

        return $payload;
    }

    public function resetPassword(string $companyCode, string $token, string $password): void
    {
        $companyCode = strtoupper(trim($companyCode));
        $token = trim($token);

        if ($companyCode === '' || $token === '') {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        $org = \App\Models\Organization::where('company_code', $companyCode)->first();
        if (! $org) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        $record = UserPasswordReset::query()
            ->where('organization_id', $org->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        $user = User::find($record->user_id);
        if (! $user) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }

        $user->forceFill(['password' => Hash::make($password)])->save();
        $user->tokens()->delete();

        UserPasswordReset::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $org->id)
            ->delete();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->forceFill(['password' => Hash::make($newPassword)])->save();
    }

    protected function dispatchResetNotification(User $user, \App\Models\Organization $org, string $resetUrl): void
    {
        $email = $user->email;
        if (! $email) {
            Log::info('Password reset link generated (no email on file)', [
                'organization' => $org->company_code,
                'username' => $user->username,
                'reset_url' => config('app.debug') ? $resetUrl : '[hidden]',
            ]);

            return;
        }

        try {
            Mail::raw(
                "Reset your password for {$org->org_name} ({$org->company_code}).\n\nOpen this link within 1 hour:\n{$resetUrl}\n",
                function ($message) use ($email, $org) {
                    $message->to($email)
                        ->subject("Password reset — {$org->org_name}");
                },
            );
        } catch (\Throwable $e) {
            Log::warning('Password reset email could not be sent', [
                'organization' => $org->company_code,
                'username' => $user->username,
                'error' => $e->getMessage(),
                'reset_url' => config('app.debug') ? $resetUrl : '[hidden]',
            ]);
        }
    }
}
