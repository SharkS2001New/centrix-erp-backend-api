<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Attendance\FieldRepHrLinkageService;
use App\Services\Erp\ErpContext;
use App\Services\Sales\MobileFieldAttendanceService;
use Illuminate\Http\Request;

class FieldRepHrLinkageController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileFieldAttendanceService $attendance,
        protected FieldRepHrLinkageService $linkage,
    ) {}

    /** GET /attendance/field-rep-hr-linkage */
    public function index(Request $request)
    {
        $gate = $this->erp->gateForUser($request->user());
        if (! $this->attendance->isEnabled($gate)) {
            abort(403, 'Field attendance is not enabled for this organization.');
        }

        $data = $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        return response()->json(
            $this->linkage->attentionSummary(
                $request->user(),
                (int) ($data['days'] ?? 30),
            ),
        );
    }
}
