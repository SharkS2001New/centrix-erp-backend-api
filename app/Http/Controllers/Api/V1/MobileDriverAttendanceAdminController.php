<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\MobileDriverAttendanceService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MobileDriverAttendanceAdminController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileDriverAttendanceService $attendance,
    ) {}

    /** GET /attendance/driver-sessions */
    public function index(Request $request)
    {
        $filters = $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'open_only' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $gate = $this->erp->gateForUser($request->user());
        $paginator = $this->attendance->paginateForViewer($request->user(), $gate, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($session) => $this->attendance->serializeSession($session))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** GET /attendance/driver-sessions/{sessionId} */
    public function show(Request $request, int $sessionId)
    {
        try {
            $session = $this->attendance->findForViewer($request->user(), $sessionId);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json($this->attendance->serializeSession($session));
    }

    /** POST /attendance/driver-sessions/{sessionId}/reopen */
    public function reopen(Request $request, int $sessionId)
    {
        $gate = $this->erp->gateForUser($request->user());

        try {
            $session = $this->attendance->reopenSession($request->user(), $gate, $sessionId);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Driver session reopened.',
            'session' => $this->attendance->serializeSession($session),
        ]);
    }
}
