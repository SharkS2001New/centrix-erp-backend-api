<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\RespondsAfterPasswordChange;
use App\Http\Controllers\Concerns\RespondsWithAuthSession;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\ApiTokenCookie;
use App\Services\Auth\AuthSessionService;
use App\Services\Auth\PasswordExpiryService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TenantAccountResolver;
use App\Services\Auth\UsernameValidator;
use App\Services\Sales\UserCartCleanupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use RespondsAfterPasswordChange;
    use RespondsWithAuthSession;

    public function __construct(
        protected AuthSessionService $sessions,
        protected TenantAccountResolver $resolver,
        protected PasswordResetService $passwordResets,
    ) {}

    public function health(Request $request)
    {
        // Browser connectivity probes — no DB/Redis work (avoids load from many open tabs).
        if ($request->boolean('connectivity')) {
            return response()->json(['ok' => true, 'checks' => ['app' => true]]);
        }

        $checks = ['app' => true];

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $checks['database'] = true;
        } catch (\Throwable) {
            return response()->json([
                'ok' => false,
                'checks' => array_merge($checks, ['database' => false]),
            ], 503);
        }

        if (config('cache.default') === 'redis') {
            try {
                Cache::store('redis')->put('health:ping', '1', 5);
                $checks['redis'] = Cache::store('redis')->get('health:ping') === '1';
            } catch (\Throwable) {
                $checks['redis'] = false;
            }

            if ($checks['redis'] === false) {
                return response()->json([
                    'ok' => false,
                    'checks' => $checks,
                ], 503);
            }
        }

        return response()->json(['ok' => true, 'checks' => $checks]);
    }

    public function organizationPreview(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
        ]);

        $code = strtoupper(trim($data['company_code']));
        $organization = Organization::findByCompanyCodeIdentifier($code);

        if (! $organization) {
            throw ValidationException::withMessages([
                'company_code' => ['Organization not found for this company code.'],
            ]);
        }

        return response()->json([
            'company_code' => $organization->company_code,
            'org_name' => $organization->org_name,
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'nullable|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'client_id' => 'required|string',
            'login_channel' => 'sometimes|in:backoffice,pos,mobile',
            'force_logout' => 'sometimes|boolean',
        ]);

        try {
            $result = $this->sessions->login(
                $data['company_code'] ?? '',
                $data['username'],
                $data['password'],
                $data['client_id'],
                (bool) ($data['force_logout'] ?? false),
                $data['login_channel'] ?? 'backoffice',
            );
        } catch (ValidationException $e) {
            if ($e->errors()['session'] ?? null) {
                return response()->json([
                    'message' => 'This user is already logged in on another device.',
                    'code' => 'session_active_elsewhere',
                ], 403);
            }
            throw $e;
        }

        return $this->respondWithAuthSession($result, $request);
    }

    public function switchWorkspace(Request $request)
    {
        $data = $request->validate([
            'login_channel' => 'required|in:backoffice,pos,mobile',
            'client_id' => 'required|string',
            'workspace_id' => ['nullable', 'string', 'max:32', Rule::in(array_keys(config('erp_workspaces', [])))],
        ]);

        $result = $this->sessions->switchLoginChannel(
            $request->user(),
            $data['client_id'],
            $data['login_channel'],
            $data['workspace_id'] ?? null,
        );

        return $this->respondWithAuthSession($result, $request);
    }

    public function memberships(Request $request)
    {
        $memberships = $this->resolver->membershipsForCanonicalUser((int) $request->user()->id);

        return response()->json([
            'memberships' => $memberships,
            'current_organization_id' => $request->user()->organization_id,
        ]);
    }

    public function switchOrganization(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string',
            'client_id' => 'required|string',
            'login_channel' => 'sometimes|in:backoffice,pos,mobile',
        ]);

        $result = $this->sessions->switchOrganization(
            $request->user(),
            $data['company_code'],
            $data['client_id'],
            $data['login_channel'] ?? 'backoffice',
        );

        return $this->respondWithAuthSession($result, $request);
    }

    public function logout(Request $request)
    {
        $plainTextToken = $request->bearerToken();
        if ((! is_string($plainTextToken) || $plainTextToken === '') && ApiTokenCookie::enabled()) {
            $cookieToken = $request->cookie((string) config('security.api_token_cookie.name', 'centrix_api_token'));
            if (is_string($cookieToken) && $cookieToken !== '') {
                $plainTextToken = $cookieToken;
            }
        }

        $user = $request->user();
        if ($user === null && is_string($plainTextToken) && $plainTextToken !== '') {
            $token = Sanctum::personalAccessTokenModel()::findToken($plainTextToken);
            $tokenable = $token?->tokenable;
            if ($tokenable instanceof User) {
                $user = $tokenable;
            }
        }

        if ($user instanceof User) {
            app(UserCartCleanupService::class)->clearAllForUser($user);
        }

        if (is_string($plainTextToken) && $plainTextToken !== '') {
            Sanctum::personalAccessTokenModel()::findToken($plainTextToken)?->delete();
        } elseif ($request->user() !== null) {
            $request->user()->currentAccessToken()?->delete();
        }

        return $this->respondWithAuthLogout();
    }

    public function me(Request $request)
    {
        return response()->json(
            $request->user()->load(['branch', 'role', 'organization']),
        );
    }

    public function updateMe(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'full_name' => 'sometimes|required|string|max:200',
            'email' => 'sometimes|nullable|email|max:255',
            'username' => 'sometimes|required|string|max:50',
        ]);

        if (isset($data['username'])) {
            app(UsernameValidator::class)->assertUniqueInOrganization(
                (int) $user->organization_id,
                (string) $data['username'],
                ignoreUserId: (int) $user->id,
            );
        }

        if ($data !== []) {
            $user->update($data);
        }

        return response()->json(
            $user->fresh()->load(['branch', 'role', 'organization']),
        );
    }

    public function forgotPassword(Request $request)
    {
        throw ValidationException::withMessages([
            'username' => ['Password reset is managed by your organization administrator. Contact them to reset your password.'],
        ]);
    }

    public function setRequiredPassword(Request $request)
    {
        $orgId = (int) ($request->user()?->organization_id ?? 0);
        $data = $request->validate([
            'password' => PasswordPolicy::validationRules($orgId ?: null),
        ]);
        PasswordPolicy::assertValid($orgId ?: null, $data['password']);

        $user = $request->user();
        if (! $user->must_change_password) {
            throw ValidationException::withMessages([
                'password' => ['Password change is not required for this account.'],
            ]);
        }

        $this->passwordResets->setRequiredPassword($user, $data['password']);

        return $this->respondAfterPasswordChange($user);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string',
            'token' => 'required|string',
            'password' => PasswordPolicy::validationRules(null),
        ]);
        PasswordPolicy::assertValid(null, $data['password']);

        $this->passwordResets->resetPassword(
            $data['company_code'],
            $data['token'],
            $data['password'],
        );

        return response()->json([
            'message' => 'Password updated. You can sign in with your new password.',
        ]);
    }

    public function changePassword(Request $request)
    {
        $orgId = (int) ($request->user()?->organization_id ?? 0);
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => PasswordPolicy::validationRules($orgId ?: null),
        ]);
        PasswordPolicy::assertValid($orgId ?: null, $data['password']);

        $this->passwordResets->changePassword(
            $request->user(),
            $data['current_password'],
            $data['password'],
        );

        return $this->respondAfterPasswordChange($request->user()->fresh());
    }

    public function skipPasswordExpiry(Request $request)
    {
        $user = $request->user();
        $status = app(PasswordExpiryService::class)->skipExpiryReminder($user);

        return response()->json([
            'message' => 'Password update deferred.',
            'password_expiry' => $status,
        ]);
    }

    public function verifyPassword(Request $request)
    {
        $data = $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'Incorrect password.',
            ]);
        }

        $token = $request->user()->currentAccessToken();
        if ($token instanceof \App\Models\PersonalAccessToken) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return response()->json(['verified' => true]);
    }
}
