<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Notifications\AdminNotificationService;
use Illuminate\Http\Request;

class FiscalPeriodController extends Controller
{
    public function __construct(
        protected FiscalPeriodService $periods,
    ) {}

    public function index(Request $request)
    {
        $year = $request->filled('year') ? (int) $request->input('year') : null;
        $orgId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => $this->periods->listForOrganization($orgId, $year),
        ]);
    }

    public function store(Request $request)
    {
        if (app()->environment('production')) {
            abort(403, 'Fiscal period seeding is not available in production. Contact support if periods are missing.');
        }

        $data = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $orgId = (int) $request->user()->organization_id;
        $this->periods->seedYear($orgId, (int) $data['year']);

        return response()->json([
            'data' => $this->periods->listForOrganization($orgId, (int) $data['year']),
        ], 201);
    }

    public function close(Request $request, int $periodId)
    {
        $period = $this->findOrgPeriod($request, $periodId);

        $closed = $this->periods->close($period, $request->user());
        app(AdminNotificationService::class)->notifyPermission($request->user(), 'accounting.manage', [
            'type' => 'info',
            'severity' => 'warning',
            'title' => 'Fiscal period closed',
            'message' => ($request->user()->full_name ?: $request->user()->username)." closed fiscal period {$closed->period_name}.",
            'action_url' => '/accounting/fiscal-periods',
        ]);

        return response()->json($closed);
    }

    public function reopen(Request $request, int $periodId)
    {
        $period = $this->findOrgPeriod($request, $periodId);

        $reopened = $this->periods->reopen($period);
        app(AdminNotificationService::class)->notifyPermission($request->user(), 'accounting.manage', [
            'type' => 'info',
            'severity' => 'danger',
            'title' => 'Fiscal period reopened',
            'message' => ($request->user()->full_name ?: $request->user()->username)." reopened fiscal period {$reopened->period_name}.",
            'action_url' => '/accounting/fiscal-periods',
        ]);

        return response()->json($reopened);
    }

    protected function findOrgPeriod(Request $request, int $periodId): FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $periodId)
            ->firstOrFail();
    }
}
