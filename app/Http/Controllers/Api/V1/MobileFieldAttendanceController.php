<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Sales\MobileFieldAttendanceService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MobileFieldAttendanceController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileFieldAttendanceService $attendance,
    ) {}

    /** GET /sales/mobile-field-attendance */
    public function index(Request $request)
    {
        $this->assertFeatureAvailable($request);

        $filters = $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'open_only' => 'nullable|boolean',
            'q' => 'nullable|string|max:200',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $gate = $this->erp->gateForUser($request->user());
        $paginator = $this->attendance->paginateForViewer($request->user(), $gate, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($session) => $this->attendance->serializeSession($session, true))
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

    /** GET /sales/mobile-field-attendance/{sessionId} */
    public function show(Request $request, int $sessionId)
    {
        $this->assertFeatureAvailable($request);

        try {
            $session = $this->attendance->findForViewer($request->user(), $sessionId);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 404);
        }

        return response()->json(
            $this->attendance->serializeSession($session, true),
        );
    }

    /** PATCH /sales/mobile-field-attendance/{sessionId} */
    public function update(Request $request, int $sessionId)
    {
        $this->assertFeatureAvailable($request);

        $data = $request->validate([
            'sign_in_at' => 'sometimes|date',
            'sign_out_at' => 'nullable|date',
        ]);

        $gate = $this->erp->gateForUser($request->user());

        try {
            $session = $this->attendance->updateSession(
                $request->user(),
                $gate,
                $sessionId,
                $data,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(
            $this->attendance->serializeSession($session, true),
        );
    }

    protected function assertFeatureAvailable(Request $request): void
    {
        $gate = $this->erp->gateForUser($request->user());

        if (! $this->attendance->isEnabled($gate)) {
            abort(403, 'Field attendance is not enabled for this organization.');
        }
    }
}
