<?php

namespace App\Services\Payroll;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeLeaveDay;
use App\Models\EmployeeOvertime;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\PayrollRunSettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCycleSettlementService
{
    /**
     * Close HR items for a processed payroll run (records kept for reporting).
     *
     * @param  array<int, array<string, mixed>>  $lineInputs
     * @param  array{
     *   include_overtime?: bool,
     *   include_other_deductions?: bool
     * }  $options
     * @return array<string, int>
     */
    public function closeForRun(PayrollRun $run, PayPeriod $period, array $lineInputs, array $options = []): array
    {
        return DB::transaction(function () use ($run, $period, $lineInputs, $options) {
            $this->restoreForRun($run, deletingRun: false);

            $counts = [
                'attendance' => 0,
                'overtime' => 0,
                'cash_advance' => 0,
                'employee_deduction' => 0,
                'leave_day' => 0,
            ];

            $includeOvertime = (bool) ($options['include_overtime'] ?? true);
            $includeOther = (bool) ($options['include_other_deductions'] ?? true);

            $start = $period->period_start->format('Y-m-d');
            $end = $period->period_end->format('Y-m-d');
            $employeeIds = collect($lineInputs)->pluck('employee_id')->unique()->filter()->values()->all();
            $orgId = (int) $period->organization_id;
            $runId = (int) $run->id;

            if ($employeeIds === []) {
                return $counts;
            }

            $attendanceRows = EmployeeAttendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereDate('attendance_date', '>=', $start)
                ->whereDate('attendance_date', '<=', $end)
                ->whereNull('payroll_run_id')
                ->get();

            foreach ($attendanceRows as $row) {
                $this->recordSettlement($runId, $orgId, PayrollRunSettlement::TYPE_ATTENDANCE, (int) $row->id, [
                    'payroll_run_id' => $row->payroll_run_id,
                ]);
                $row->update(['payroll_run_id' => $runId]);
                $counts['attendance']++;
            }

            $leaveRows = EmployeeLeaveDay::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereDate('end_date', '>=', $start)
                ->whereDate('start_date', '<=', $end)
                ->whereNull('payroll_run_id')
                ->get();

            foreach ($leaveRows as $row) {
                $this->recordSettlement($runId, $orgId, PayrollRunSettlement::TYPE_LEAVE_DAY, (int) $row->id, [
                    'payroll_run_id' => $row->payroll_run_id,
                ]);
                $row->update(['payroll_run_id' => $runId]);
                $counts['leave_day']++;
            }

            if ($includeOvertime) {
                $overtimeRows = EmployeeOvertime::query()
                    ->whereIn('employee_id', $employeeIds)
                    ->whereIn('status', ['approved', 'pending'])
                    ->whereNull('payroll_run_id')
                    ->whereDate('work_date', '>=', $start)
                    ->whereDate('work_date', '<=', $end)
                    ->get();

                foreach ($overtimeRows as $row) {
                    $this->recordSettlement($runId, $orgId, PayrollRunSettlement::TYPE_OVERTIME, (int) $row->id, [
                        'status' => $row->status,
                        'pay_period_id' => $row->pay_period_id,
                        'payroll_run_id' => $row->payroll_run_id,
                    ]);
                    $row->update([
                        'status' => 'paid',
                        'pay_period_id' => $period->id,
                        'payroll_run_id' => $runId,
                    ]);
                    $counts['overtime']++;
                }
            }

            if ($includeOther) {
                foreach ($lineInputs as $line) {
                    foreach ($line['payroll_meta']['deductions_detail'] ?? [] as $item) {
                        if (($item['type'] ?? '') === 'cash_advance' && ! empty($item['id'])) {
                            $advance = EmployeeCashAdvance::find($item['id']);
                            if (! $advance) {
                                continue;
                            }
                            $deduct = (float) ($item['amount'] ?? 0);
                            if ($deduct <= 0) {
                                continue;
                            }
                            $this->recordSettlement($runId, $orgId, PayrollRunSettlement::TYPE_CASH_ADVANCE, (int) $advance->id, [
                                'balance' => (float) $advance->balance,
                                'status' => $advance->status,
                                'amount_deducted' => $deduct,
                            ]);
                            $advance->balance = round(max(0, (float) $advance->balance - $deduct), 2);
                            if ($advance->balance <= 0) {
                                $advance->balance = 0;
                                $advance->status = 'repaid';
                            }
                            $advance->save();
                            $counts['cash_advance']++;
                        }
                        if (($item['type'] ?? '') === 'employee_deduction' && ! empty($item['id'])) {
                            $this->recordSettlement($runId, $orgId, PayrollRunSettlement::TYPE_EMPLOYEE_DEDUCTION, (int) $item['id'], [
                                'name' => $item['name'] ?? null,
                                'amount' => (float) ($item['amount'] ?? 0),
                                'employee_id' => (int) ($line['employee_id'] ?? 0),
                            ]);
                            $counts['employee_deduction']++;
                        }
                    }
                }
            }

            return $counts;
        });
    }

    /**
     * Undo cycle close when a payroll run is deleted or re-processed.
     *
     * @return array<string, int>
     */
    public function restoreForRun(PayrollRun $run, bool $deletingRun = true): array
    {
        return DB::transaction(function () use ($run, $deletingRun) {
            $counts = [
                'attendance' => 0,
                'overtime' => 0,
                'cash_advance' => 0,
                'employee_deduction' => 0,
                'leave_day' => 0,
            ];

            $settlements = PayrollRunSettlement::query()
                ->where('payroll_run_id', $run->id)
                ->orderByDesc('id')
                ->get();

            if ($settlements->isEmpty()) {
                return $counts;
            }

            foreach ($settlements as $settlement) {
                $snapshot = $settlement->snapshot ?? [];

                match ($settlement->item_type) {
                    PayrollRunSettlement::TYPE_ATTENDANCE => $this->restoreAttendance((int) $settlement->item_id, $snapshot, $counts),
                    PayrollRunSettlement::TYPE_LEAVE_DAY => $this->restoreLeaveDay((int) $settlement->item_id, $snapshot, $counts),
                    PayrollRunSettlement::TYPE_OVERTIME => $this->restoreOvertime((int) $settlement->item_id, $snapshot, $counts),
                    PayrollRunSettlement::TYPE_CASH_ADVANCE => $this->restoreCashAdvance((int) $settlement->item_id, $snapshot, $counts),
                    PayrollRunSettlement::TYPE_EMPLOYEE_DEDUCTION => $counts['employee_deduction']++,
                    default => null,
                };

                $settlement->delete();
            }

            if ($deletingRun) {
                EmployeeAttendance::query()
                    ->where('payroll_run_id', $run->id)
                    ->update(['payroll_run_id' => null]);
                EmployeeLeaveDay::query()
                    ->where('payroll_run_id', $run->id)
                    ->update(['payroll_run_id' => null]);
                EmployeeOvertime::query()
                    ->where('payroll_run_id', $run->id)
                    ->update([
                        'payroll_run_id' => null,
                        'pay_period_id' => null,
                        'status' => 'approved',
                    ]);
            }

            return $counts;
        });
    }

    public static function assertNotPayrollLocked(?int $payrollRunId, string $resourceLabel = 'record'): void
    {
        if ($payrollRunId) {
            throw ValidationException::withMessages([
                'payroll_run_id' => [
                    "This {$resourceLabel} is closed on a processed payroll run. Delete that payroll run first to edit it.",
                ],
            ]);
        }
    }

    protected function recordSettlement(
        int $runId,
        int $orgId,
        string $type,
        int $itemId,
        array $snapshot,
    ): void {
        PayrollRunSettlement::query()->create([
            'payroll_run_id' => $runId,
            'organization_id' => $orgId,
            'item_type' => $type,
            'item_id' => $itemId,
            'snapshot' => $snapshot,
        ]);
    }

    /** @param  array<string, int>  $counts */
    protected function restoreAttendance(int $id, array $snapshot, array &$counts): void
    {
        $row = EmployeeAttendance::find($id);
        if (! $row) {
            return;
        }
        $row->update(['payroll_run_id' => $snapshot['payroll_run_id'] ?? null]);
        $counts['attendance']++;
    }

    /** @param  array<string, int>  $counts */
    protected function restoreLeaveDay(int $id, array $snapshot, array &$counts): void
    {
        $row = EmployeeLeaveDay::find($id);
        if (! $row) {
            return;
        }
        $row->update(['payroll_run_id' => $snapshot['payroll_run_id'] ?? null]);
        $counts['leave_day']++;
    }

    /** @param  array<string, int>  $counts */
    protected function restoreOvertime(int $id, array $snapshot, array &$counts): void
    {
        $row = EmployeeOvertime::find($id);
        if (! $row) {
            return;
        }
        $row->update([
            'status' => $snapshot['status'] ?? 'approved',
            'pay_period_id' => $snapshot['pay_period_id'] ?? null,
            'payroll_run_id' => $snapshot['payroll_run_id'] ?? null,
        ]);
        $counts['overtime']++;
    }

    /** @param  array<string, int>  $counts */
    protected function restoreCashAdvance(int $id, array $snapshot, array &$counts): void
    {
        $row = EmployeeCashAdvance::find($id);
        if (! $row) {
            return;
        }
        if (array_key_exists('balance', $snapshot)) {
            $row->balance = round((float) $snapshot['balance'], 2);
        }
        $row->status = $snapshot['status'] ?? 'open';
        $row->save();
        $counts['cash_advance']++;
    }
}
