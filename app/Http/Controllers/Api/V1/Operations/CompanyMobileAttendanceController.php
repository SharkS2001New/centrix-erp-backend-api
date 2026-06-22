<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Attendance\CompanyMobileAttendanceService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CompanyMobileAttendanceController extends Controller
{
    public function __construct(protected CompanyMobileAttendanceService $attendance) {}

    /** GET /company-mobile-attendance/device-status */
    public function deviceStatus(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
            'device_identifier' => 'nullable|string|max:120',
            'branch_id' => 'nullable|integer',
        ]);

        try {
            $organization = $this->attendance->resolveOrganization($data['company_code']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(
            $this->attendance->deviceStatus(
                $organization,
                $data['device_identifier'] ?? null,
                isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            ),
        );
    }

    /** GET /company-mobile-attendance/branches */
    public function branches(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
        ]);

        try {
            $organization = $this->attendance->resolveOrganization($data['company_code']);
            $branches = $this->attendance->listBranches($organization);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $branches]);
    }

    /** GET /company-mobile-attendance/config */
    public function config(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
            'device_identifier' => 'required|string|max:120',
        ]);

        try {
            $organization = $this->attendance->resolveOrganization($data['company_code']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(
            $this->attendance->publicConfig($organization, $data['device_identifier']),
        );
    }

    /** GET /company-mobile-attendance/employees */
    public function employees(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
            'device_identifier' => 'required|string|max:120',
            'q' => 'nullable|string|max:120',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        try {
            $organization = $this->attendance->resolveOrganization($data['company_code']);
            $employees = $this->attendance->searchEmployees(
                $organization,
                $data['q'] ?? null,
                (int) ($data['limit'] ?? 25),
                $data['device_identifier'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $employees]);
    }

    /** GET /company-mobile-attendance/employees/{employeeId}/session */
    public function employeeSession(Request $request, string $employeeId)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
            'device_identifier' => 'required|string|max:120',
        ]);

        try {
            $organization = $this->attendance->resolveOrganization($data['company_code']);
            $state = $this->attendance->employeeSessionState(
                $organization,
                (int) $employeeId,
                $data['device_identifier'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($state);
    }

    /** POST /company-mobile-attendance/clock-in */
    public function clockIn(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
            'device_identifier' => 'required|string|max:120',
            'employee_id' => 'required|integer',
            'photo' => 'required|image|max:10240',
            'face_embedding' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        try {
            $organization = $this->attendance->resolveOrganization($data['company_code']);
            $result = $this->attendance->clockIn($organization, $data);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => ($result['face_enrolled'] ?? false)
                ? 'Face enrolled and shift started successfully.'
                : 'Shift started successfully.',
            'face_enrolled' => (bool) ($result['face_enrolled'] ?? false),
            'session' => $this->attendance->serializeSession($result['session']),
        ], 201);
    }

    /** POST /company-mobile-attendance/clock-out */
    public function clockOut(Request $request)
    {
        $data = $request->validate([
            'company_code' => 'required|string|max:45',
            'device_identifier' => 'required|string|max:120',
            'employee_id' => 'required|integer',
            'photo' => 'required|image|max:10240',
            'face_embedding' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'address' => 'nullable|string|max:500',
        ]);

        try {
            $organization = $this->attendance->resolveOrganization($data['company_code']);
            $result = $this->attendance->clockOut($organization, $data);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Shift ended successfully.',
            'face_enrolled' => (bool) ($result['face_enrolled'] ?? false),
            'session' => $result['session'],
            'attendance' => $result['attendance'],
        ]);
    }
}
