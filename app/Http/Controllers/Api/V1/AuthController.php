<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthSessionService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\TenantAccountResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuthSessionService $sessions,
        protected TenantAccountResolver $resolver,
        protected PasswordResetService $passwordResets,
    ) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'client_id' => 'required|string',
            'login_channel' => 'sometimes|in:backoffice,pos,mobile',
            'force_logout' => 'sometimes|boolean',
        ]);

        try {
            $result = $this->sessions->login(
                $data['company_code'],
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
        return response()->json($request->user());
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string',
            'username' => 'required|string',
        ]);

        return response()->json(
            $this->passwordResets->requestReset($data['company_code'], $data['username']),
        );
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string',
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

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
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $this->passwordResets->changePassword(
            $request->user(),
            $data['current_password'],
            $data['password'],
        );

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
