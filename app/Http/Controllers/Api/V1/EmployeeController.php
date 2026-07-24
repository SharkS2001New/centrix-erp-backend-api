<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Services\Auth\UserLoginService;
use App\Services\Cache\OrganizationCache;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Support\UploadedImageProcessor;
use Illuminate\Http\Request;
use App\Support\StoredPublicFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class EmployeeController extends BaseResourceController
{
    use FindsOrganizationEmployee;

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
        $fields = strtolower(trim((string) $request->input('fields', '')));
        $lean = $fields === 'lean' || $request->boolean('lightweight');

        // Pickers / list rows: skip bank, NOK, emergency, user, shift graph.
        $relations = $lean
            ? [
                'department:id,organization_id,department_name',
                'position:id,organization_id,position_title',
                'branch:id,organization_id,branch_name',
            ]
            : $this->employeeRelations();

        $query = Employee::query()->with($relations);
        $user = $request->user();
        if ($user) {
            $this->access()->scopeOrganization($query, $user, 'organization_id', $request);
            $this->access()->scopeBranchIfLimited($query, $user);
        }

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

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $sort = strtolower(trim((string) $request->input('sort', '')));
        $sortDir = strtolower((string) $request->input('sort_dir', '')) === 'desc' ? 'desc' : 'asc';
        if (in_array($sort, ['created_at', 'id', 'hire_date'], true)) {
            $query->orderBy($sort, $sortDir);
        } else {
            $query->orderBy('full_name');
        }

        return response()->json($query->paginate($perPage));
    }

    /** GET /employees/summary */
    public function summary(Request $request)
    {
        $user = $request->user();
        $orgId = $user ? $this->access()->organizationId($user, $request) : null;
        $ttl = max(60, min(300, (int) config('cache.hub_summary_ttl', 120)));

        $build = function () use ($request, $user) {
            $query = Employee::query();
            if ($user) {
                $this->access()->scopeOrganization($query, $user, 'organization_id', $request);
                $this->access()->scopeBranchIfLimited($query, $user);
            }

            $row = (clone $query)->selectRaw(
                'COUNT(*) AS total,
                 SUM(CASE WHEN is_active != 0 OR is_active IS NULL THEN 1 ELSE 0 END) AS active,
                 COALESCE(SUM(CASE WHEN is_active != 0 OR is_active IS NULL THEN base_salary ELSE 0 END), 0) AS payroll_cost',
            )->first();

            $departmentQuery = \App\Models\Department::query()->where('is_active', '!=', false);
            if ($user) {
                $this->access()->scopeOrganization($departmentQuery, $user, 'organization_id', $request);
            }

            $byDepartment = (clone $query)
                ->where(function ($inner) {
                    $inner->where('is_active', '!=', false)->orWhereNull('is_active');
                })
                ->selectRaw('department_id, COUNT(*) as aggregate_count')
                ->groupBy('department_id')
                ->get()
                ->mapWithKeys(fn ($r) => [
                    (string) ($r->department_id ?? 'null') => (int) $r->aggregate_count,
                ])
                ->all();

            // Matches FE isPayrollEligible / payroll auto-process rules.
            $payrollEligible = (clone $query)
                ->where(function ($inner) {
                    $inner->where('is_active', '!=', false)->orWhereNull('is_active');
                })
                ->where('employment_status', 'active')
                ->where('base_salary', '>', 0)
                ->whereNotNull('shift_id')
                ->count();

            return [
                'total' => (int) ($row->total ?? 0),
                'active' => (int) ($row->active ?? 0),
                'departments' => $departmentQuery->count(),
                'payroll_cost' => (float) ($row->payroll_cost ?? 0),
                'payroll_eligible' => (int) $payrollEligible,
                'by_department_id' => $byDepartment,
            ];
        };

        if ($orgId) {
            $branchKey = $this->access()->branchId($user) ?? 'all';

            return response()->json(
                OrganizationCache::remember(
                    $orgId,
                    'employees.summary:'.$branchKey,
                    $ttl,
                    $build,
                ),
            );
        }

        return response()->json($build());
    }

    public function show(Request $request, string $id)
    {
        $employee = $this->findOrgEmployee($id, $request)->load($this->employeeRelations());

        return response()->json($employee);
    }

    public function store(Request $request)
    {
        $data = $this->prepareEmployeeData($request);
        $employee = Employee::create($data);
        $employee = $employee->load($this->employeeRelations());
        app(UserLoginService::class)->syncFromEmployee($employee);

        return response()->json($employee, 201);
    }

    public function update(Request $request, string $id)
    {
        $employee = $this->findOrgEmployee($id, $request);
        $employee->update($this->prepareEmployeeData($request, $employee));
        $employee = $employee->fresh($this->employeeRelations());
        app(UserLoginService::class)->syncFromEmployee($employee);

        return response()->json($employee);
    }

    public function payrollLines(Request $request, int $employee)
    {
        $this->findOrgEmployee($employee, $request);

        $query = PayrollLine::query()
            ->where('employee_id', $employee)
            ->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->paginate($perPage));
    }

    /** GET /employees/{id}/photo/file */
    public function photoFile(Request $request, int $employee)
    {
        $model = $this->findOrgEmployee($employee, $request);

        if (! StoredPublicFile::exists($model->photo_path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return StoredPublicFile::response($model->photo_path, 'image/jpeg');
    }

    /** POST /employees/{id}/photo */
    public function uploadPhoto(Request $request, int $employee)
    {
        $model = $this->findOrgEmployee($employee, $request);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($model->photo_path) {
            Storage::disk('public')->delete($model->photo_path);
        }

        $stored = app(UploadedImageProcessor::class)->storePublicImage(
            $request->file('image'),
            \App\Support\OrganizationPublicStorage::path($model->organization_id, 'employees', (string) $model->id, 'photo'),
        );
        $model->update(['photo_path' => $stored['path']]);

        return response()->json($model->fresh($this->employeeRelations()));
    }

    /** DELETE /employees/{id}/photo */
    public function deletePhoto(Request $request, int $employee)
    {
        $model = $this->findOrgEmployee($employee, $request);

        if ($model->photo_path) {
            Storage::disk('public')->delete($model->photo_path);
            $model->update(['photo_path' => null]);
        }

        return response()->json($model->fresh($this->employeeRelations()));
    }

    protected function prepareEmployeeData(Request $request, ?Employee $existing = null): array
    {
        $data = $this->validatedEmployee($request, $existing);

        if (array_key_exists('work_weekdays', $data)) {
            $days = $data['work_weekdays'];
            if (! is_array($days) || $days === []) {
                $data['work_weekdays'] = null;
            } else {
                $data['work_weekdays'] = array_values(array_unique(array_map('intval', $days)));
            }
        }

        $user = $request->user();
        if ($user) {
            $orgId = (int) ($this->access()->organizationId($user, $request) ?? 0);
            if ($orgId > 0) {
                $data['organization_id'] = $orgId;
            }
            if (! empty($data['branch_id'])) {
                $this->access()->assertBranchInOrganization($user, (int) $data['branch_id'], $request);
            }
            $limitedBranch = $this->access()->branchId($user);
            if ($limitedBranch !== null) {
                if (! empty($data['branch_id']) && (int) $data['branch_id'] !== $limitedBranch) {
                    abort(403, 'You can only operate within your assigned branch.');
                }
                $data['branch_id'] = $limitedBranch;
            }
        }

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

        $data['user_id'] = ! empty($data['user_id']) ? (int) $data['user_id'] : null;

        $status = $data['employment_status'] ?? $existing?->employment_status ?? 'active';
        $salary = (float) ($data['base_salary'] ?? $existing?->base_salary ?? 0);
        $shiftId = $data['shift_id'] ?? $existing?->shift_id;
        if ($status === 'active' && $salary > 0 && empty($shiftId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'shift_id' => ['Assign a work shift for payroll, attendance, and overtime.'],
            ]);
        }

        $orgId = (int) ($data['organization_id'] ?? $existing?->organization_id ?? 0);
        if ($orgId && empty($data['probation_end_date']) && ! empty($data['hire_date']) && ! $existing?->probation_end_date) {
            $months = (int) (HrPayrollSettingsResolver::forOrganizationId($orgId)['default_probation_months'] ?? 0);
            if ($months > 0) {
                $data['probation_end_date'] = \Carbon\Carbon::parse($data['hire_date'])
                    ->addMonths($months)
                    ->toDateString();
            }
        }

        return $data;
    }

    protected function validatedEmployee(Request $request, ?Employee $existing = null): array
    {
        return $request->validate([
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'department_id' => 'nullable|integer|exists:departments,id',
            'position_id' => 'nullable|integer|exists:positions,id',
            'shift_id' => 'nullable|integer|exists:work_shifts,id',
            'work_weekdays' => 'nullable|array',
            'work_weekdays.*' => 'integer|min:0|max:6',
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
