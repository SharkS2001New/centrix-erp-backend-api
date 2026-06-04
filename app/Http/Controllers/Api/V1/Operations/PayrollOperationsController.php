<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Payroll\KenyaStatutoryCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollOperationsController extends Controller
{
    public function __construct(protected KenyaStatutoryCalculator $calculator) {}

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
    public function processRun(Request $request, int $runId)
    {
        $run = PayrollRun::findOrFail($runId);
        $payload = $request->validate([
            'auto_calculate' => 'nullable|boolean',
            'lines' => 'required|array',
            'lines.*.employee_id' => 'required|integer',
            'lines.*.gross_pay' => 'nullable|numeric|min:0',
            'lines.*.other_deductions' => 'nullable|numeric|min:0',
            'lines.*.nssf' => 'nullable|numeric|min:0',
            'lines.*.shif' => 'nullable|numeric|min:0',
            'lines.*.housing_levy' => 'nullable|numeric|min:0',
            'lines.*.paye' => 'nullable|numeric|min:0',
            'lines.*.deductions' => 'nullable|numeric|min:0',
        ]);

        $autoCalculate = (bool) ($payload['auto_calculate'] ?? true);

        return DB::transaction(function () use ($run, $payload, $autoCalculate) {
            PayrollLine::where('payroll_run_id', $run->id)->delete();
            $grossTotal = 0;
            $netTotal = 0;

            foreach ($payload['lines'] as $lineInput) {
                $employee = Employee::findOrFail($lineInput['employee_id']);
                $gross = (float) ($lineInput['gross_pay'] ?? $employee->base_salary ?? 0);
                $other = (float) ($lineInput['other_deductions'] ?? 0);

                if ($autoCalculate || ! isset($lineInput['paye'])) {
                    $calc = $this->calculator->calculateMonthly($gross, $other);
                } else {
                    $calc = $this->manualLine($lineInput, $gross, $other);
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

            return response()->json($run->fresh()->load([]));
        });
    }

    /**
     * POST /payroll/runs/{runId}/process-auto
     * Process all active employees in the org using base_salary and Kenya auto-calc.
     */
    public function processAuto(Request $request, int $runId)
    {
        $run = PayrollRun::with('payPeriod')->findOrFail($runId);
        $orgId = $request->user()?->organization_id;

        $employees = Employee::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('employment_status', 'active')
            ->where('is_active', true)
            ->where('base_salary', '>', 0)
            ->orderBy('full_name')
            ->get();

        $lines = $employees->map(fn (Employee $e) => [
            'employee_id' => $e->id,
            'gross_pay' => (float) $e->base_salary,
            'other_deductions' => 0,
        ])->all();

        $request->merge([
            'auto_calculate' => true,
            'lines' => $lines,
        ]);

        return $this->processRun($request, $runId);
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
