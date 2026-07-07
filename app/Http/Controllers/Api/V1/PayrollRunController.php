<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\PayPeriod;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\Notifications\ActionRequestService;
use App\Services\Payroll\PayrollRunApprovalService;
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
        $query = PayrollRun::query()
            ->with(['payPeriod', 'approvedByUser:id,full_name,username', 'paidByUser:id,full_name,username'])
            ->withCount('lines as employee_count');

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
        $viewer = $request->user();
        $paginator->getCollection()->transform(fn (PayrollRun $run) => $this->runWithMeta($run, $viewer));

        return response()->json($paginator);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pay_period_id' => 'required|integer|exists:pay_periods,id',
            'run_date' => 'required|date',
            'status' => 'nullable|in:draft,pending_approval,approved,processed,paid,void',
            'total_gross' => 'nullable|numeric|min:0',
            'total_net' => 'nullable|numeric|min:0',
        ]);

        $period = PayPeriod::findOrFail((int) $data['pay_period_id']);
        app(PayrollRunScheduleService::class)->assertCanRunPayrollForPeriod($period);

        if (! isset($data['status'])) {
            $hr = HrPayrollSettingsResolver::forOrganizationId((int) $period->organization_id);
            $data['status'] = ($hr['require_payroll_approval'] ?? false) ? 'pending_approval' : 'draft';
        }

        $run = PayrollRun::create($data);
        if ($run->status === 'pending_approval' && $request->user()) {
            app(PayrollRunApprovalService::class)->requestApproval($request->user(), $run->fresh('payPeriod'));
        }

        return response()->json($this->runWithMeta($run->load('payPeriod'), $request->user()), 201);
    }

    public function show(Request $request, string $id)
    {
        $run = PayrollRun::with([
            'payPeriod',
            'approvedByUser:id,full_name,username',
            'paidByUser:id,full_name,username',
            'processedByUser:id,full_name,username',
        ])
            ->withCount('lines as employee_count')
            ->findOrFail($id);

        return response()->json($this->runWithMeta($run, $request->user()));
    }

    /**
     * Admin only — delete run and lines; restore closed attendance, overtime, advances, etc.
     */
    public function destroy(Request $request, string $id)
    {
        if (! $request->user()?->is_admin) {
            return response()->json(['message' => 'Only administrators can delete payroll runs.'], 403);
        }

        $run = PayrollRun::with('payPeriod')->findOrFail($id);
        $orgId = (int) ($run->payPeriod?->organization_id ?? 0);
        $schedule = app(PayrollRunScheduleService::class);

        if (! $schedule->canDeletePayrollRun($run->created_at, $run->run_date, $orgId ?: null)) {
            $expires = $schedule->deleteLockExpiresAt($run->created_at, $run->run_date, $orgId ?: null);
            $lockMinutes = $orgId
                ? (int) \App\Services\Hr\HrPayrollSettingsResolver::forOrganizationId($orgId)['payroll_run_delete_lock_minutes']
                : PayrollRunScheduleService::DELETE_LOCK_MINUTES;

            return response()->json([
                'message' => 'Payroll run can only be deleted within '
                    . $lockMinutes
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

    protected function runWithMeta(PayrollRun $run, ?User $viewer = null): PayrollRun
    {
        $meta = $run->deleteMeta();
        $run->setAttribute('can_delete', $meta['can_delete']);
        $run->setAttribute('delete_locked_after', $meta['delete_locked_after']);
        $run->setAttribute('delete_lock_minutes', $meta['delete_lock_minutes']);

        if ($viewer && $run->status === 'pending_approval') {
            $run->setAttribute(
                'action_request',
                app(ActionRequestService::class)->presentPendingFor(
                    $viewer,
                    'payroll_run',
                    'payroll_run',
                    (int) $run->id,
                ),
            );
        }

        return $run;
    }
}
