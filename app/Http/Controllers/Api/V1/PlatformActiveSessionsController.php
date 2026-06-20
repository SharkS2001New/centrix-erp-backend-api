<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\PlatformActiveSessionService;
use Illuminate\Http\Request;

class PlatformActiveSessionsController extends Controller
{
    public function __construct(
        protected PlatformActiveSessionService $sessions,
    ) {}

    /** GET /api/v1/admin/active-sessions */
    public function index()
    {
        return response()->json([
            'data' => $this->sessions->groupedActiveSessions(),
        ]);
    }

    /** DELETE /api/v1/admin/active-sessions/{token} — sign out one device/session */
    public function destroy(int $token)
    {
        $this->sessions->revokeSession($token);

        return response()->json([
            'message' => 'Session ended. The user was signed out on that device.',
        ]);
    }

    /** POST /api/v1/admin/active-sessions/{token}/disable-user */
    public function disableUser(Request $request, int $token)
    {
        $user = $this->sessions->disableUserForSession($token);

        return response()->json([
            'message' => 'User login disabled and all sessions ended.',
            'user' => $user->only(['id', 'username', 'is_active']),
        ]);
    }
}
