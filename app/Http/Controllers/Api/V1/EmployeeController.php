<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Employee;
use App\Models\PayrollLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends BaseResourceController
{
    protected function employeeRelations(): array
    {
        return [
            'department',
            'position',
            'shift',
            'branch',
            'user',
            'reportsTo',
            'bankAccounts',
            'emergencyContacts',
            'nextOfKin',
        ];
    }

    protected function modelClass(): string
    {
        return Employee::class;
    }

    public function index(Request $request)
    {
        $query = Employee::query()->with($this->employeeRelations());

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('full_name', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('employee_code', 'like', "%{$q}%")
                    ->orWhere('payroll_number', 'like', "%{$q}%")
                    ->orWhere('job_title', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('national_id', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderBy('full_name')->paginate($perPage));
    }

    public function show(string $id)
    {
        $employee = Employee::with($this->employeeRelations())->findOrFail($id);

        return response()->json($employee);
    }

    public function store(Request $request)
    {
        $data = $this->prepareEmployeeData($request);
        $employee = Employee::create($data);

        return response()->json($employee->load($this->employeeRelations()), 201);
    }

    public function update(Request $request, string $id)
    {
        $employee = Employee::findOrFail($id);
        $employee->update($this->prepareEmployeeData($request, $employee));

        return response()->json($employee->fresh($this->employeeRelations()));
    }

    public function payrollLines(Request $request, int $employee)
    {
        Employee::findOrFail($employee);

        $query = PayrollLine::query()
            ->where('employee_id', $employee)
            ->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    /** GET /employees/{id}/photo/file */
    public function photoFile(int $employee)
    {
        $model = Employee::findOrFail($employee);

        if (! $model->photo_path || ! Storage::disk('public')->exists($model->photo_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $absolute = Storage::disk('public')->path($model->photo_path);
        $mime = Storage::disk('public')->mimeType($model->photo_path) ?: 'image/jpeg';

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** POST /employees/{id}/photo */
    public function uploadPhoto(Request $request, int $employee)
    {
        $model = Employee::findOrFail($employee);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($model->photo_path) {
            Storage::disk('public')->delete($model->photo_path);
        }

        $path = $request->file('image')->store('employees/'.$model->id, 'public');
        $model->update(['photo_path' => $path]);

        return response()->json($model->fresh($this->employeeRelations()));
    }

    /** DELETE /employees/{id}/photo */
    public function deletePhoto(int $employee)
    {
        $model = Employee::findOrFail($employee);

        if ($model->photo_path) {
            Storage::disk('public')->delete($model->photo_path);
            $model->update(['photo_path' => null]);
        }

        return response()->json($model->fresh($this->employeeRelations()));
    }

    protected function prepareEmployeeData(Request $request, ?Employee $existing = null): array
    {
        $data = $this->validatedEmployee($request, $existing);

        $data['nationality'] = 'Kenyan';
        $data['country'] = 'Kenya';

        $data['full_name'] = Employee::composeFullName(
            $data['first_name'] ?? $existing?->first_name,
            $data['middle_name'] ?? $existing?->middle_name,
            $data['last_name'] ?? $existing?->last_name,
            $data['full_name'] ?? $existing?->full_name,
        );

        if (empty($data['employee_code'])) {
            $orgId = (int) ($data['organization_id'] ?? $existing?->organization_id);
            $data['employee_code'] = Employee::generateNextEmployeeCode($orgId);
        }

        if (empty($data['payroll_number'])) {
            $data['payroll_number'] = $data['employee_code'];
        }

        if (($data['employment_status'] ?? 'active') !== 'active') {
            $data['is_active'] = false;
        }

        $status = $data['employment_status'] ?? $existing?->employment_status ?? 'active';
        $salary = (float) ($data['base_salary'] ?? $existing?->base_salary ?? 0);
        $shiftId = $data['shift_id'] ?? $existing?->shift_id;
        if ($status === 'active' && $salary > 0 && empty($shiftId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'shift_id' => ['Assign a work shift for payroll, attendance, and overtime.'],
            ]);
        }

        return $data;
    }

    protected function validatedEmployee(Request $request, ?Employee $existing = null): array
    {
        return $request->validate([
            'organization_id' => $existing ? 'sometimes|integer|exists:organizations,id' : 'required|integer|exists:organizations,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'position_id' => 'nullable|integer|exists:positions,id',
            'shift_id' => 'nullable|integer|exists:work_shifts,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'reports_to_employee_id' => 'nullable|integer|exists:employees,id',
            'employee_code' => 'nullable|string|max:45',
            'payroll_number' => 'nullable|string|max:45',
            'first_name' => ($existing ? 'sometimes|' : '') . 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => ($existing ? 'sometimes|' : '') . 'required|string|max:100',
            'full_name' => 'nullable|string|max:200',
            'gender' => 'nullable|in:male,female,other,undisclosed',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:100',
            'national_id' => 'nullable|string|max:45',
            'id_document_type' => 'nullable|in:national_id,passport',
            'marital_status' => 'nullable|in:single,married,divorced,widowed,other',
            'personal_email' => 'nullable|email|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:45',
            'alt_phone' => 'nullable|string|max:45',
            'physical_address' => 'nullable|string|max:500',
            'postal_address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'county' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'photo_path' => 'nullable|string|max:500',
            'employment_status' => 'nullable|in:active,suspended,terminated,retired',
            'employment_type' => 'nullable|in:permanent,contract,casual,intern',
            'job_title' => 'nullable|string|max:100',
            'hire_date' => 'nullable|date',
            'confirmation_date' => 'nullable|date',
            'probation_end_date' => 'nullable|date',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date',
            'notice_period_days' => 'nullable|integer|min:0',
            'pay_frequency' => 'nullable|in:monthly,biweekly,weekly',
            'base_salary' => 'nullable|numeric|min:0',
            'monthly_allowance' => 'nullable|numeric|min:0',
            'kra_pin' => 'nullable|string|max:45',
            'nssf_number' => 'nullable|string|max:45',
            'sha_number' => 'nullable|string|max:45',
            'housing_levy_number' => 'nullable|string|max:45',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
