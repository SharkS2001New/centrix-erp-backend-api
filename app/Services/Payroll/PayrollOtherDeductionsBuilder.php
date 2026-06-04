<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeDeduction;
use App\Models\PayrollDeductionType;

class PayrollOtherDeductionsBuilder
{
    /**
     * Cash advances, org-wide deductions, then per-employee deductions (override by type).
     * Fixed amounts and cash-advance repayments are never prorated by attendance or pay-period length.
     * Percentage rows use $contractGrossForPercent (monthly basic + monthly allowances).
     *
     * @return array{total: float, detail: array<int, array<string, mixed>>}
     */
    public function build(Employee $employee, float $contractGrossForPercent): array
    {
        $other = 0.0;
        $detail = [];

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
            ];
        }

        $employeeDeductions = EmployeeDeduction::query()
            ->with('deductionType')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $overriddenTypeIds = $employeeDeductions
            ->pluck('deduction_type_id')
            ->filter()
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
            ];
        }

        return [
            'total' => round($other, 2),
            'detail' => $detail,
        ];
    }
}
