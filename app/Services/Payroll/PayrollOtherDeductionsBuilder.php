<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeDeduction;
use App\Models\PayrollDeductionType;
use App\Services\Hr\HrPayrollSettingsResolver;

class PayrollOtherDeductionsBuilder
{
    /**
     * Cash advances, org-wide deductions, then per-employee deductions (override by type).
     * Fixed amounts and cash-advance repayments are never prorated by attendance or pay-period length.
     * Percentage rows use $contractGrossForPercent (monthly basic + monthly allowances).
     *
     * @return array{total: float, detail: array<int, array<string, mixed>>}
     */
    public function build(Employee $employee, float $contractGrossForPercent, ?array $hrSettings = null): array
    {
        $hr = $hrSettings ?? HrPayrollSettingsResolver::forOrganizationId((int) $employee->organization_id);
        $other = 0.0;
        $detail = [];

        if ($hr['enable_cash_advance_deductions'] && $hr['deduct_cash_advances_on_payroll']) {
            $advances = EmployeeCashAdvance::query()
                ->where('employee_id', $employee->id)
                ->where('status', 'open')
                ->where('balance', '>', 0)
                ->orderBy('advance_date')
                ->get();

            foreach ($advances as $advance) {
                $advAmt = $advance->payrollDeductionAmount();
                if ($advAmt <= 0) {
                    continue;
                }
                $other += $advAmt;
                $label = 'Cash advance';
                if ($advance->notes) {
                    $label .= ' — ' . $advance->notes;
                }
                $detail[] = [
                    'type' => 'cash_advance',
                    'scope' => 'employee',
                    'id' => $advance->id,
                    'name' => $label,
                    'repayment_mode' => $advance->repayment_mode ?? 'full_next_cycle',
                    'balance_before' => (float) $advance->balance,
                    'amount' => round($advAmt, 2),
                    'prorated' => false,
                    'frequency' => 'per_cycle',
                ];
            }
        }

        if (! ($hr['include_other_deductions_in_payroll'] ?? true)) {
            return [
                'total' => round($other, 2),
                'detail' => $detail,
            ];
        }

        $employeeDeductions = EmployeeDeduction::query()
            ->with('deductionType')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->whereNull('payroll_run_id')
            ->orderBy('name')
            ->get();

        $overriddenTypeIds = $employeeDeductions
            ->pluck('deduction_type_id')
            ->filter()
            ->unique()
            ->values();

        $appliedOneTimeTypeIds = EmployeeDeduction::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('deduction_type_id')
            ->where('frequency', EmployeeDeduction::FREQUENCY_ONE_TIME)
            ->whereNotNull('payroll_run_id')
            ->pluck('deduction_type_id')
            ->unique()
            ->values();

        $orgTypes = PayrollDeductionType::query()
            ->where('organization_id', $employee->organization_id)
            ->where('applies_to_all', true)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($orgTypes as $type) {
            if ($overriddenTypeIds->contains($type->id)) {
                continue;
            }
            if ($type->isOneTime() && $appliedOneTimeTypeIds->contains($type->id)) {
                continue;
            }
            $amt = $type->payrollDeductionAmount($contractGrossForPercent);
            if ($amt <= 0) {
                continue;
            }
            $other += $amt;
            $detail[] = [
                'type' => 'organization_deduction',
                'scope' => 'all_employees',
                'id' => $type->id,
                'name' => $type->name,
                'calc_type' => $type->calc_type,
                'percentage' => $type->calc_type === 'percentage' ? (float) $type->default_percentage : null,
                'amount' => $amt,
                'prorated' => false,
                'frequency' => $type->isOneTime()
                    ? PayrollDeductionType::FREQUENCY_ONE_TIME
                    : PayrollDeductionType::FREQUENCY_PER_CYCLE,
            ];
        }

        foreach ($employeeDeductions as $ded) {
            $amt = $ded->payrollDeductionAmount($contractGrossForPercent);
            if ($amt <= 0) {
                continue;
            }
            $other += $amt;
            $detail[] = [
                'type' => 'employee_deduction',
                'scope' => 'employee',
                'id' => $ded->id,
                'deduction_type_id' => $ded->deduction_type_id,
                'name' => $ded->name ?: 'Deduction',
                'calc_type' => $ded->calc_type,
                'percentage' => $ded->calc_type === 'percentage' ? (float) $ded->percentage : null,
                'amount' => round($amt, 2),
                'prorated' => false,
                'frequency' => $ded->isOneTime()
                    ? EmployeeDeduction::FREQUENCY_ONE_TIME
                    : EmployeeDeduction::FREQUENCY_PER_CYCLE,
            ];
        }

        return [
            'total' => round($other, 2),
            'detail' => $detail,
        ];
    }
}
