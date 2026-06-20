<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Auth\AuthSessionService;
use App\Services\Auth\PasswordPolicy;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TenantAccountResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuthSessionService $sessions,
        protected TenantAccountResolver $resolver,
        protected PasswordResetService $passwordResets,
    ) {}

    public function health()
    {
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

        return response()->json(['ok' => true, 'checks' => $checks]);
    }

    public function organizationPreview(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
        ]);

        $code = strtoupper(trim($data['company_code']));
        $organization = Organization::query()
            ->whereRaw('UPPER(company_code) = ?', [$code])
            ->first();

        if (! $organization) {
            throw ValidationException::withMessages([
                'company_code' => ['Organization not found for this company code.'],
            ]);
        }

        return response()->json([
            'company_code' => $organization->company_code,
            'org_name' => $organization->org_name,
            'organization_id' => $organization->id,
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

        return response()->json($result);
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

        return response()->json($result);
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

        return response()->json($result);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json(
            $request->user()->load(['branch', 'role', 'organization']),
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

        return response()->json([
            'message' => 'Password updated successfully.',
            'must_change_password' => false,
        ]);
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

        return response()->json([
            'message' => 'Password updated successfully.',
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
