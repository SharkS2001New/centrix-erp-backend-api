<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollRunScheduleService;
use Illuminate\Http\Request;

class PayPeriodController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return PayPeriod::class;
    }

    public function index(Request $request)
    {
        $query = PayPeriod::query()->withCount('payrollRuns as payroll_runs_count');
        $orgId = $request->user()?->organization_id;
        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderByDesc('period_start')->orderByDesc('id')->paginate($perPage),
        );
    }

    public function store(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        $data = $request->validate([
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'period_code' => 'required|string|max:45',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'status' => 'nullable|in:open,closed',
        ]);

        if ($orgId) {
            $data['organization_id'] = $orgId;
        }

        if (empty($data['organization_id'])) {
            return response()->json(['message' => 'Organization is required.'], 422);
        }

        $existing = PayPeriod::query()
            ->where('organization_id', $data['organization_id'])
            ->where('period_code', $data['period_code'])
            ->first();

        if ($existing) {
            return response()->json($existing, 200);
        }

        $period = new PayPeriod([
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
        ]);
        if (! app(PayrollRunScheduleService::class)->canRunPayrollForPeriod($period)) {
            return response()->json([
                'message' => app(PayrollRunScheduleService::class)->runBlockedMessage($period),
            ], 422);
        }

        $model = PayPeriod::create($data);

        return response()->json($model, 201);
    }

    /** POST /pay-periods/ensure-runnable — auto-create pay periods allowed for payroll today */
    public function ensureRunnable(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization is required.'], 422);
        }

        $periods = app(PayrollRunScheduleService::class)->ensureRunnablePeriods((int) $orgId);

        return response()->json([
            'data' => $periods,
            'schedule' => app(PayrollRunScheduleService::class)->describe(),
        ]);
    }

    /** DELETE /pay-periods/{id} — admin only; blocked if payroll runs exist */
    public function destroy(string $id)
    {
        if (! request()->user()?->is_admin) {
            return response()->json(['message' => 'Only administrators can delete pay periods.'], 403);
        }

        $query = PayPeriod::query();
        if ($orgId = request()->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }
        $period = $query->findOrFail((int) $id);

        $runsCount = PayrollRun::query()->where('pay_period_id', $period->id)->count();
        if ($runsCount > 0) {
            return response()->json([
                'message' => "Cannot delete this pay period: {$runsCount} payroll run(s) are linked to it. Delete those payroll runs first.",
                'payroll_runs_count' => $runsCount,
            ], 422);
        }

        $period->delete();

        return response()->json([
            'message' => 'Pay period deleted.',
        ]);
    }
}
