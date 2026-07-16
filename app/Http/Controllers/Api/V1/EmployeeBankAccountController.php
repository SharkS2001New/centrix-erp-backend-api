<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use Illuminate\Http\Request;

class EmployeeBankAccountController extends Controller
{
    use FindsOrganizationEmployee;

    public function index(int $employee)
    {
        $this->findOrgEmployee($employee);

        return response()->json(
            EmployeeBankAccount::where('employee_id', $employee)->orderByDesc('is_primary')->get()
        );
    }

    public function store(Request $request, int $employee)
    {
        $this->findOrgEmployee($employee);
        $data = $this->validatedAccount($request);
        $data['employee_id'] = $employee;
        $data = $this->normalizeAccount($data);

        if (! empty($data['is_primary'])) {
            EmployeeBankAccount::where('employee_id', $employee)->update(['is_primary' => false]);
        }

        $account = EmployeeBankAccount::create($data);

        return response()->json($account, 201);
    }

    public function update(Request $request, int $employee, int $bankAccount)
    {
        $account = EmployeeBankAccount::where('employee_id', $employee)->findOrFail($bankAccount);
        $data = $this->validatedAccount($request, updating: true);
        $data = $this->normalizeAccount(array_merge($account->only([
            'bank_name', 'bank_branch', 'account_number', 'account_name', 'payment_method',
        ]), $data));

        if (! empty($data['is_primary'])) {
            EmployeeBankAccount::where('employee_id', $employee)->update(['is_primary' => false]);
        }

        $account->update($data);

        return response()->json($account->fresh());
    }

    /** @return array<string, mixed> */
    private function validatedAccount(Request $request, bool $updating = false): array
    {
        $method = $request->input('payment_method', 'bank_transfer');
        $required = $updating ? 'sometimes' : 'required';

        $rules = [
            'bank_branch' => 'nullable|string|max:200',
            'payment_method' => 'nullable|in:bank_transfer,mpesa,cash,cheque',
            'is_primary' => 'nullable|boolean',
        ];

        if (in_array($method, ['bank_transfer', 'cheque'], true)) {
            $rules['bank_name'] = "{$required}|string|max:200";
            $rules['account_number'] = "{$required}|string|max:45";
            $rules['account_name'] = "{$required}|string|max:200";
        } elseif ($method === 'mpesa') {
            $rules['account_number'] = "{$required}|string|max:45";
            $rules['account_name'] = "{$required}|string|max:200";
            $rules['bank_name'] = 'nullable|string|max:200';
        } else {
            $rules['account_name'] = "{$required}|string|max:200";
            $rules['bank_name'] = 'nullable|string|max:200';
            $rules['account_number'] = 'nullable|string|max:45';
        }

        return $request->validate($rules);
    }

    /** @param  array<string, mixed>  $data */
    private function normalizeAccount(array $data): array
    {
        $method = $data['payment_method'] ?? 'bank_transfer';

        if ($method === 'mpesa') {
            $data['bank_name'] = trim((string) ($data['bank_name'] ?? '')) ?: 'M-Pesa';
        } elseif ($method === 'cash') {
            $data['bank_name'] = trim((string) ($data['bank_name'] ?? '')) ?: 'Cash';
            $data['account_number'] = trim((string) ($data['account_number'] ?? '')) ?: 'N/A';
        } elseif ($method === 'cheque' && empty(trim((string) ($data['bank_name'] ?? '')))) {
            $data['bank_name'] = 'Cheque';
        }

        return $data;
    }

    public function destroy(int $employee, int $bankAccount)
    {
        EmployeeBankAccount::where('employee_id', $employee)->findOrFail($bankAccount)->delete();

        return response()->json(null, 204);
    }
}
