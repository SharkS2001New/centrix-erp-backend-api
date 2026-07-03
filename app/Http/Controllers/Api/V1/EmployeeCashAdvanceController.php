<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\EmployeeCashAdvance;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\Hr\CashAdvanceApprovalService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeCashAdvanceController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return EmployeeCashAdvance::class;
    }

    public function index(Request $request)
    {
        $query = EmployeeCashAdvance::query()->with('employee');

        if ($orgId = $request->user()?->organization_id) {
            $query->where('organization_id', $orgId);
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('advance_date')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $employee = Employee::findOrFail($data['employee_id']);
        $hr = HrPayrollSettingsResolver::forOrganizationId((int) $employee->organization_id);
        if (! $hr['enable_cash_advance_deductions']) {
            throw ValidationException::withMessages([
                'employee_id' => ['Employee cash advances are disabled in HR settings.'],
            ]);
        }
        $data['organization_id'] = $data['organization_id'] ?? $employee->organization_id;
        $data['balance'] = $data['balance'] ?? $data['amount'];
        $data['repayment_mode'] = $data['repayment_mode'] ?? 'full_next_cycle';
        if (! isset($data['status'])) {
            $data['status'] = 'pending';
        }
        $data = $this->normalizeRepayment($data);

        $advance = EmployeeCashAdvance::create($data)->load('employee');
        if ($advance->status === 'pending' && $request->user()) {
            app(CashAdvanceApprovalService::class)->requestApproval($request->user(), $advance);
        }

        return response()->json($advance, 201);
    }

    /** POST /employee-cash-advances/{id}/approve */
    public function approve(string $id)
    {
        $row = $this->findScoped($id);
        if ($row->status === 'open') {
            return response()->json($row->load('employee'));
        }
        if ($row->status !== 'pending') {
            return response()->json(['message' => 'Only pending advances can be approved.'], 422);
        }
        $row->update(['status' => 'open']);
        app(ActionRequestService::class)->markResolvedFromDomain(
            'cash_advance',
            'employee_cash_advance',
            (int) $row->id,
            'approved',
            request()->user(),
        );

        return response()->json($row->fresh('employee'));
    }

    /** POST /employee-cash-advances/{id}/reject */
    public function reject(string $id)
    {
        $row = $this->findScoped($id);
        if ($row->status !== 'pending') {
            return response()->json(['message' => 'Only pending advances can be rejected.'], 422);
        }
        $row->update(['status' => 'cancelled']);
        app(ActionRequestService::class)->markResolvedFromDomain(
            'cash_advance',
            'employee_cash_advance',
            (int) $row->id,
            'rejected',
            request()->user(),
        );

        return response()->json($row->fresh('employee'));
    }

    public function update(Request $request, string $id)
    {
        $row = $this->findScoped($id);
        $data = $this->normalizeRepayment($this->validated($request, updating: true));
        $row->update($data);

        return response()->json($row->fresh('employee'));
    }

    /** @param array<string, mixed> $data */
    protected function normalizeRepayment(array $data): array
    {
        $mode = trim((string) ($data['repayment_mode'] ?? 'full_next_cycle'));
        if (! in_array($mode, ['full_next_cycle', 'fixed_per_cycle'], true)) {
            $mode = 'full_next_cycle';
        }
        $data['repayment_mode'] = $mode;

        if ($mode === 'full_next_cycle') {
            $data['repayment_amount'] = null;
        } elseif (empty($data['repayment_amount']) || (float) $data['repayment_amount'] <= 0) {
            $data['repayment_mode'] = 'full_next_cycle';
            $data['repayment_amount'] = null;
        }

        return $data;
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'employee_id' => $req . 'integer|exists:employees,id',
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'advance_date' => $req . 'date',
            'amount' => $req . 'numeric|min:0',
            'balance' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:pending,open,repaid,cancelled',
            'repayment_mode' => 'nullable|in:full_next_cycle,fixed_per_cycle',
            'repayment_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);
    }
}
