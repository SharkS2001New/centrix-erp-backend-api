<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PayPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollCycleSettlementService;
use App\Services\Payroll\PayrollRunScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollRunController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return PayrollRun::class;
    }

    public function index(Request $request)
    {
        $query = PayrollRun::query()->with('payPeriod')->withCount('lines as employee_count');

        if ($orgId = $request->user()?->organization_id) {
            $query->whereHas('payPeriod', fn ($q) => $q->where('organization_id', $orgId));
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $paginator = $query->orderByDesc('run_date')->orderByDesc('id')->paginate($perPage);
        $paginator->getCollection()->transform(fn (PayrollRun $run) => $this->runWithMeta($run));

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pay_period_id' => 'required|integer|exists:pay_periods,id',
            'run_date' => 'required|date',
            'status' => 'nullable|in:draft,processed,paid,void',
            'total_gross' => 'nullable|numeric|min:0',
            'total_net' => 'nullable|numeric|min:0',
        ]);

        $period = PayPeriod::findOrFail((int) $data['pay_period_id']);
        app(PayrollRunScheduleService::class)->assertCanRunPayrollForPeriod($period);

        $run = PayrollRun::create($data);

        return response()->json($this->runWithMeta($run->load('payPeriod')), 201);
    }

    public function show(string $id)
    {
        $run = PayrollRun::with('payPeriod')
            ->withCount('lines as employee_count')
            ->findOrFail($id);

        return response()->json($this->runWithMeta($run));
    }

    /**
     * Admin only — delete run and lines; restore closed attendance, overtime, advances, etc.
     */
    public function destroy(string $id)
    {
        if (! request()->user()?->is_admin) {
            return response()->json(['message' => 'Only administrators can delete payroll runs.'], 403);
        }

        $run = PayrollRun::findOrFail($id);

        if (! app(PayrollRunScheduleService::class)->canDeletePayrollRun($run->created_at, $run->run_date)) {
            $expires = app(PayrollRunScheduleService::class)
                ->deleteLockExpiresAt($run->created_at, $run->run_date);

            return response()->json([
                'message' => 'Payroll run can only be deleted within '
                    . PayrollRunScheduleService::DELETE_LOCK_MINUTES
                    . ' minutes of creation (locked after '
                    . $expires->timezone(config('app.timezone'))->format('M j, Y g:i A')
                    . ').',
            ], 422);
        }

        return DB::transaction(function () use ($run) {
            $restored = app(PayrollCycleSettlementService::class)->restoreForRun($run);
            PayrollLine::query()->where('payroll_run_id', $run->id)->delete();
            $run->delete();

            return response()->json([
                'message' => 'Payroll run deleted. Related HR records were reopened for that period.',
                'restored' => $restored,
            ], 200);
        });
    }

    protected function runWithMeta(PayrollRun $run): PayrollRun
    {
        $meta = $run->deleteMeta();
        $run->setAttribute('can_delete', $meta['can_delete']);
        $run->setAttribute('delete_locked_after', $meta['delete_locked_after']);
        $run->setAttribute('delete_lock_minutes', $meta['delete_lock_minutes']);

        return $run;
    }
}
