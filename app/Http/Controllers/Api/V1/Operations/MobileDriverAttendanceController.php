<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\MobileDriverAttendanceService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MobileDriverAttendanceController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileDriverAttendanceService $attendance,
    ) {}

    /** GET /mobile/driver/attendance/session */
    public function session(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $enabled = $this->attendance->isEnabled($gate);
        $openSession = $enabled ? $this->attendance->openSessionForUser($user) : null;

        return response()->json([
            'feature_enabled' => $enabled,
            'session' => $openSession
                ? $this->attendance->serializeSession($openSession)
                : null,
        ]);
    }

    /** GET /mobile/driver/attendance/summary */
    public function summary(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        return response()->json(
            $this->attendance->userDaySummary($user, $gate),
        );
    }

    /** POST /mobile/driver/attendance/sign-in */
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
            Log::error('mobile driver attendance sign-in database error', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $this->databaseErrorMessage($exception),
            ], 503);
        } catch (\Throwable $exception) {
            Log::error('mobile driver attendance sign-in failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to record driver sign-in.',
            ], 500);
        }

        return response()->json([
            'message' => 'Signed in successfully.',
            'session' => $this->attendance->serializeSession($session),
        ], 201);
    }

    /** POST /mobile/driver/attendance/suspend */
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

    /** POST /mobile/driver/attendance/resume */
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

    /** POST /mobile/driver/attendance/sign-out */
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
            Log::error('mobile driver attendance sign-out database error', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $this->databaseErrorMessage($exception),
            ], 503);
        } catch (\Throwable $exception) {
            Log::error('mobile driver attendance sign-out failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to record driver sign-out.',
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
        if (str_contains($message, 'mobile_driver_attendance_sessions')) {
            return 'Driver attendance database tables are missing. Run php artisan migrate on the API server.';
        }

        return 'Database error while saving driver attendance. Contact your administrator.';
    }
}
