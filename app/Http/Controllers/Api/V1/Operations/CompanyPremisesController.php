<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Attendance\AttendanceBranchPremisesService;
use App\Services\Attendance\CompanyMobileAttendanceService;
use App\Services\Attendance\HrAttendanceSettingsResolver;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CompanyPremisesController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected CompanyMobileAttendanceService $attendance,
        protected AttendanceBranchPremisesService $branchPremises,
    ) {}

    /** GET /attendance/company-premises */
    public function show(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $settings = HrAttendanceSettingsResolver::forOrganization($org);

        return response()->json([
            'attendance_capture_mode' => $settings['attendance_capture_mode'],
            'default_radius_metres' => $settings['company_premises_radius_metres'],
            'company_face_match_threshold' => $settings['company_face_match_threshold'],
            'branches' => $this->branchPremises->listForOrganization($org),
        ]);
    }

    /** POST /attendance/company-premises — password required to save coordinates */
    public function update(Request $request)
    {
        $user = $request->user();
        $org = $this->erp->resolveOrganization($request);

        $data = $request->validate([
            'password' => 'required|string',
            'branch_id' => 'required|integer',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius_metres' => 'nullable|numeric|min:1|max:500',
        ]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Incorrect password.'],
            ]);
        }

        $row = $this->branchPremises->saveForBranch(
            $org,
            $user,
            (int) $data['branch_id'],
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['radius_metres']) ? (float) $data['radius_metres'] : null,
        );

        return response()->json([
            'message' => 'Branch premises location saved.',
            'branch_id' => $row->branch_id,
            'latitude' => (float) $row->latitude,
            'longitude' => (float) $row->longitude,
            'radius_metres' => (float) $row->radius_metres,
            'has_premises_location' => true,
        ]);
    }

    /** GET /attendance/company-mobile-sessions */
    public function sessions(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);
        $filters = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'open_only' => 'nullable|boolean',
            'branch_id' => 'nullable|integer',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $paginator = $this->attendance->paginateSessions($org, $filters);
        $paginator->getCollection()->transform(
            fn ($session) => $this->attendance->serializeSession($session, true),
        );

        return response()->json($paginator);
    }
}
