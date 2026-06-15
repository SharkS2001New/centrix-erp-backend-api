<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Accounting\PayrollJournalService;
use App\Services\Erp\ErpContext;
use App\Services\Payroll\KenyaStatutoryCalculator;
use App\Services\Payroll\KenyaStatutoryReference;
use App\Models\PayPeriod;
use App\Services\Payroll\PayrollCycleSettlementService;
use App\Services\Payroll\PayrollEarningsService;
use App\Services\Payroll\PayrollRunScheduleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollOperationsController extends Controller
{
    public function __construct(
        protected KenyaStatutoryCalculator $calculator,
        protected PayrollEarningsService $earnings,
        protected PayrollCycleSettlementService $settlements,
        protected ErpContext $erp,
    ) {}

    /** GET /payroll/kenya-statutory — formulas and rates for UI */
    public function kenyaStatutory()
    {
        return response()->json(KenyaStatutoryReference::describe());
    }

    /** GET /payroll/run-schedule — when payroll may run and which periods apply today */
    public function runSchedule(Request $request)
    {
        $orgId = $request->user()?->organization_id;
        $schedule = app(PayrollRunScheduleService::class)->describe();
        if ($orgId) {
            $schedule['periods'] = app(PayrollRunScheduleService::class)
                ->ensureRunnablePeriods((int) $orgId)
                ->values();
        }

        return response()->json($schedule);
    }

    /** GET /payroll/calculate — preview Kenya statutory breakdown for a gross amount */
    public function calculate(Request $request)
    {
        $data = $request->validate([
            'gross_pay' => 'required|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
        ]);

        return response()->json(
            $this->calculator->calculateMonthly(
                (float) $data['gross_pay'],
                (float) ($data['other_deductions'] ?? 0),
            )
        );
    }

    /**
     * POST /payroll/runs/{runId}/process
     * Lines may omit statutory fields; set auto_calculate=true to compute PAYE/NSSF/SHIF/AHL.
     */
    public function processRun(Request $request, string $runId)
    {
        $run = PayrollRun::with('payPeriod')->findOrFail((int) $runId);
        if ($run->payPeriod) {
            app(PayrollRunScheduleService::class)->assertCanRunPayrollForPeriod($run->payPeriod);
        }
        $payload = $request->validate([
            'auto_calculate' => 'nullable|boolean',
            'close_cycle' => 'nullable|boolean',
            'include_overtime' => 'nullable|boolean',
            'include_other_deductions' => 'nullable|boolean',
            'include_deductions' => 'nullable|boolean',
            'lines' => 'required|array',
            'lines.*.employee_id' => 'required|integer',
            'lines.*.gross_pay' => 'nullable|numeric|min:0',
            'lines.*.other_deductions' => 'nullable|numeric|min:0',
            'lines.*.payroll_meta' => 'nullable|array',
            'lines.*.nssf' => 'nullable|numeric|min:0',
            'lines.*.shif' => 'nullable|numeric|min:0',
            'lines.*.housing_levy' => 'nullable|numeric|min:0',
            'lines.*.paye' => 'nullable|numeric|min:0',
            'lines.*.deductions' => 'nullable|numeric|min:0',
        ]);

        $autoCalculate = (bool) ($payload['auto_calculate'] ?? true);
        $closeCycle = (bool) ($payload['close_cycle'] ?? true);
        $settlementOptions = [
            'include_overtime' => (bool) ($payload['include_overtime'] ?? true),
            'include_other_deductions' => (bool) (
                $payload['include_other_deductions']
                ?? $payload['include_deductions']
                ?? true
            ),
        ];

        return DB::transaction(function () use ($request, $run, $payload, $autoCalculate, $closeCycle, $settlementOptions) {
            PayrollLine::where('payroll_run_id', $run->id)->delete();
            $grossTotal = 0;
            $netTotal = 0;

            foreach ($payload['lines'] as $lineInput) {
                $employee = Employee::findOrFail($lineInput['employee_id']);
                $basic = (float) ($lineInput['basic_salary'] ?? $employee->base_salary ?? 0);
                $allowances = (float) ($lineInput['allowances'] ?? 0);
                $meta = $lineInput['payroll_meta'] ?? [];
                $overtime = (float) ($meta['overtime'] ?? 0);
                $gross = (float) ($lineInput['gross_pay'] ?? ($basic + $allowances + $overtime));
                $other = (float) ($lineInput['other_deductions'] ?? 0);

                if ($autoCalculate || ! isset($lineInput['paye'])) {
                    $calc = $this->calculator->calculateMonthly($gross, $other);
                } else {
                    $calc = $this->manualLine($lineInput, $gross, $other);
                }

                $calc['basic_salary'] = $basic;
                $calc['allowances'] = $allowances;
                if (! empty($lineInput['payroll_meta'])) {
                    $calc['payroll'] = $lineInput['payroll_meta'];
                }

                PayrollLine::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'gross_pay' => $calc['gross_pay'],
                    'nssf' => $calc['nssf'],
                    'shif' => $calc['shif'],
                    'housing_levy' => $calc['housing_levy'],
                    'paye' => $calc['paye'],
                    'other_deductions' => $calc['other_deductions'],
                    'deductions' => $calc['deductions'],
                    'net_pay' => $calc['net_pay'],
                    'taxable_income' => $calc['taxable_income'],
                    'employer_nssf' => $calc['employer_nssf'],
                    'employer_housing' => $calc['employer_housing'],
                    'statutory_meta' => $calc,
                ]);

                $grossTotal += $calc['gross_pay'];
                $netTotal += $calc['net_pay'];
            }

            $run->update([
                'status' => 'processed',
                'total_gross' => round($grossTotal, 2),
                'total_net' => round($netTotal, 2),
                'processed_by' => $request->user()->id,
            ]);

            $run = $run->fresh('payPeriod');
            $closed = [];
            if ($closeCycle && $run->payPeriod) {
                $closed = $this->settlements->closeForRun(
                    $run,
                    $run->payPeriod,
                    $payload['lines'],
                    $settlementOptions,
                );
            }

            $gate = $this->erp->gateForUser($request->user());
            app(PayrollJournalService::class)->postIfEnabled($run->fresh('lines'), $request->user(), $gate);

            return response()->json(array_merge($run->toArray(), [
                'cycle_closed' => $closed,
            ]));
        });
    }

    /**
     * POST /payroll/runs/{runId}/process-auto
     * Uses attendance, leave, overtime, deductions, and cash advances when enabled.
     */
    public function processAuto(Request $request, string $runId)
    {
        $run = PayrollRun::with('payPeriod')->findOrFail((int) $runId);
        $period = $run->payPeriod;
        if (! $period) {
            return response()->json(['message' => 'Payroll run has no pay period.'], 422);
        }

        app(PayrollRunScheduleService::class)->assertCanRunPayrollForPeriod($period);

        $orgId = $request->user()?->organization_id;

        $options = $request->validate([
            'department_id' => 'nullable|integer|exists:departments,id',
            'include_allowances' => 'nullable|boolean',
            'include_other_deductions' => 'nullable|boolean',
            'include_deductions' => 'nullable|boolean',
            'include_overtime' => 'nullable|boolean',
            'use_attendance_proration' => 'nullable|boolean',
        ]);

        $earningsOptions = [
            'include_allowances' => (bool) ($options['include_allowances'] ?? true),
            'include_other_deductions' => (bool) (
                $options['include_other_deductions']
                ?? $options['include_deductions']
                ?? true
            ),
            'include_overtime' => (bool) ($options['include_overtime'] ?? true),
            'use_attendance_proration' => (bool) ($options['use_attendance_proration'] ?? true),
        ];

        $departmentId = $options['department_id'] ?? null;

        $employees = Employee::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->where('employment_status', 'active')
            ->where('is_active', true)
            ->where('base_salary', '>', 0)
            ->orderBy('full_name')
            ->get();

        $lines = [];
        $skipped = [];

        foreach ($employees as $employee) {
            if (! $employee->shift_id) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'name' => $employee->full_name,
                    'reason' => 'No work shift assigned',
                ];

                continue;
            }

            $built = $this->earnings->buildLineInput($employee, $period, $earningsOptions);
            if ($built === null) {
                $skipped[] = [
                    'employee_id' => $employee->id,
                    'name' => $employee->full_name,
                    'reason' => 'No scheduled work days in pay period',
                ];

                continue;
            }

            $lines[] = $built;
        }

        if ($lines === []) {
            return response()->json([
                'message' => 'No eligible employees to process. Assign a work shift and ensure the pay period has working days.',
                'skipped' => $skipped,
            ], 422);
        }

        $request->merge([
            'auto_calculate' => true,
            'close_cycle' => true,
            'include_overtime' => $earningsOptions['include_overtime'],
            'include_other_deductions' => $earningsOptions['include_other_deductions'],
            'lines' => $lines,
        ]);

        $response = $this->processRun($request, $runId);

        $data = $response->getData(true);
        if (is_array($data)) {
            $data['skipped_employees'] = $skipped;
            $data['processed_count'] = count($lines);
        }

        return response()->json($data, $response->getStatusCode());
    }

    /** @param array<string, mixed> $lineInput */
    protected function manualLine(array $lineInput, float $gross, float $other): array
    {
        $nssf = (float) ($lineInput['nssf'] ?? 0);
        $shif = (float) ($lineInput['shif'] ?? 0);
        $housing = (float) ($lineInput['housing_levy'] ?? 0);
        $paye = (float) ($lineInput['paye'] ?? 0);
        $deductions = (float) ($lineInput['deductions'] ?? ($nssf + $shif + $housing + $paye + $other));
        $taxable = (float) max(0, $gross - $nssf - $shif - $housing);

        return [
            'gross_pay' => round($gross, 2),
            'nssf' => $nssf,
            'nssf_tier1' => $nssf,
            'nssf_tier2' => 0,
            'shif' => $shif,
            'housing_levy' => $housing,
            'taxable_income' => $taxable,
            'paye_before_relief' => $paye,
            'personal_relief' => 0,
            'insurance_relief' => 0,
            'paye' => $paye,
            'other_deductions' => $other,
            'deductions' => round($deductions, 2),
            'net_pay' => round($gross - $deductions, 2),
            'employer_nssf' => $nssf,
            'employer_housing' => $housing,
            'employer_total' => round($nssf + $housing, 2),
            'effective_label' => 'manual',
        ];
    }
}
