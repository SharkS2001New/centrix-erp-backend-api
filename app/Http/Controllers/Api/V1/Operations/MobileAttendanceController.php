<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Sales\MobileFieldAttendanceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MobileAttendanceController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileFieldAttendanceService $attendance,
    ) {}

    /** GET /mobile/attendance/session
     *  ?lean=1 — compact payload for logout / status probes (no work-hour math).
     */
    public function session(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $enabled = $this->attendance->isEnabled($gate);
        $openSession = $enabled ? $this->attendance->openSessionForUser($user) : null;
        $lean = $request->boolean('lean');

        return response()->json([
            'feature_enabled' => $enabled,
            'session' => $openSession
                ? ($lean
                    ? $this->attendance->serializeSessionStatus($openSession)
                    : $this->attendance->serializeSession($openSession))
                : null,
        ]);
    }

    /** GET /mobile/attendance/summary — today's work summary for the signed-in rep. */
    public function summary(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        return response()->json(
            $this->attendance->userDaySummary($user, $gate),
        );
    }

    /** POST /mobile/attendance/sign-in */
    public function signIn(Request $request)
    {
        $data = $request->validate([
            'photo' => 'required|image|max:10240',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
            'device_identifier' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        try {
            $session = $this->attendance->signIn($user, $gate, $data);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (QueryException $exception) {
            Log::error('mobile attendance sign-in database error', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $this->databaseErrorMessage($exception),
            ], 503);
        } catch (\Throwable $exception) {
            Log::error('mobile attendance sign-in failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to record sign-in. Ensure attendance storage is configured on the server.',
            ], 500);
        }

        return response()->json([
            'message' => 'Signed in successfully.',
            'session' => $this->attendance->serializeSession($session),
        ], 201);
    }

    /** POST /mobile/attendance/suspend — pause session on app logout without signing out. */
    public function suspend(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        try {
            $session = $this->attendance->suspend($user, $gate);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Session suspended.',
            'session' => $this->attendance->serializeSession($session),
        ]);
    }

    /** POST /mobile/attendance/resume — continue a same-day suspended session after login. */
    public function resume(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        try {
            $session = $this->attendance->resume($user, $gate);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Session resumed.',
            'session' => $this->attendance->serializeSession($session),
        ]);
    }

    /** POST /mobile/attendance/sign-out */
    public function signOut(Request $request)
    {
        $data = $request->validate([
            'photo' => 'required|image|max:10240',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        try {
            $session = $this->attendance->signOut($user, $gate, $data);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (QueryException $exception) {
            Log::error('mobile attendance sign-out database error', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $this->databaseErrorMessage($exception),
            ], 503);
        } catch (\Throwable $exception) {
            Log::error('mobile attendance sign-out failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to record sign-out. Ensure attendance storage is configured on the server.',
            ], 500);
        }

        return response()->json([
            'message' => 'Signed out successfully.',
            'session' => $this->attendance->serializeSession($session),
        ]);
    }

    protected function databaseErrorMessage(QueryException $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'mobile_rep_attendance_sessions')) {
            return 'Attendance database tables are missing or outdated. Run php artisan migrate on the API server.';
        }

        return 'Database error while saving attendance. Contact your administrator.';
    }
}
