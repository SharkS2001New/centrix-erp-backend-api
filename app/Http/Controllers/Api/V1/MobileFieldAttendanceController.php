<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Attendance\FieldRepHrLinkageService;
use App\Services\Erp\ErpContext;
use App\Services\Sales\MobileFieldAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class MobileFieldAttendanceController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileFieldAttendanceService $attendance,
        protected FieldRepHrLinkageService $linkage,
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
        $items = collect($paginator->items());
        $orgId = (int) $request->user()->organization_id;
        $linksByUser = $this->linkage->linksForSessions($items, $orgId);
        $lookbackDays = $this->lookbackDaysFromFilters($filters);

        return response()->json([
            'data' => $items
                ->map(function ($session) use ($linksByUser) {
                    $hrLink = $linksByUser[(int) $session->user_id] ?? null;

                    return $this->attendance->serializeSession($session, true, $hrLink);
                })
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'hr_linkage' => $this->linkage->attentionSummary($request->user(), $lookbackDays),
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
            $this->attendance->serializeSession(
                $session,
                true,
                $this->linkage->describeUserLink(
                    $session->user ?? \App\Models\User::findOrFail($session->user_id),
                ),
            ),
        );
    }

    /** GET /sales/mobile-field-attendance/{sessionId}/sign-in-photo/file */
    public function signInPhotoFile(Request $request, int $sessionId)
    {
        return $this->photoFile($request, $sessionId, 'sign_in');
    }

    /** GET /sales/mobile-field-attendance/{sessionId}/sign-out-photo/file */
    public function signOutPhotoFile(Request $request, int $sessionId)
    {
        return $this->photoFile($request, $sessionId, 'sign_out');
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
            $this->attendance->serializeSession(
                $session,
                true,
                $this->linkage->describeUserLink(
                    $session->user ?? \App\Models\User::findOrFail($session->user_id),
                ),
            ),
        );
    }

    protected function assertFeatureAvailable(Request $request): void
    {
        $gate = $this->erp->gateForUser($request->user());

        if (! $this->attendance->isEnabled($gate)) {
            abort(403, 'Field attendance is not enabled for this organization.');
        }
    }

    protected function photoFile(Request $request, int $sessionId, string $kind)
    {
        $this->assertFeatureAvailable($request);

        try {
            $session = $this->attendance->findForViewer($request->user(), $sessionId);
        } catch (InvalidArgumentException) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $path = $kind === 'sign_in'
            ? $session->sign_in_photo_path
            : $session->sign_out_photo_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $absolute = Storage::disk('public')->path($path);
        $mime = Storage::disk('public')->mimeType($path) ?: 'image/jpeg';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** @param  array<string, mixed>  $filters */
    protected function lookbackDaysFromFilters(array $filters): int
    {
        if (! empty($filters['from_date'])) {
            $from = \Carbon\Carbon::parse($filters['from_date'])->startOfDay();
            $to = ! empty($filters['to_date'])
                ? \Carbon\Carbon::parse($filters['to_date'])->endOfDay()
                : now()->endOfDay();

            return max(1, min(365, $from->diffInDays($to) + 1));
        }

        return 30;
    }
}
