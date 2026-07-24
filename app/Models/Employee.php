<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Employee extends Model
{
    use HasFactory;

    protected $table = 'employees';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'department_id',
        'position_id',
        'shift_id',
        'user_id',
        'reports_to_employee_id',
        'employee_code',
        'payroll_number',
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'gender',
        'date_of_birth',
        'nationality',
        'national_id',
        'id_document_type',
        'marital_status',
        'personal_email',
        'email',
        'phone',
        'alt_phone',
        'physical_address',
        'postal_address',
        'city',
        'county',
        'country',
        'photo_path',
        'employment_status',
        'employment_type',
        'job_title',
        'hire_date',
        'confirmation_date',
        'probation_end_date',
        'contract_start_date',
        'contract_end_date',
        'notice_period_days',
        'pay_frequency',
        'base_salary',
        'monthly_allowance',
        'kra_pin',
        'nssf_number',
        'sha_number',
        'housing_levy_number',
        'is_active',
        'work_weekdays',
        'bank_lunch_as_work',
    ];

    protected $appends = ['photo_url'];

    protected $casts = [
        'is_active' => 'boolean',
        'bank_lunch_as_work' => 'boolean',
        'base_salary' => 'decimal:2',
        'monthly_allowance' => 'decimal:2',
        'hire_date' => 'date',
        'date_of_birth' => 'date',
        'confirmation_date' => 'date',
        'probation_end_date' => 'date',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'work_weekdays' => 'array',
    ];

    protected function photoUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->photo_path) {
                return null;
            }

            $base = rtrim((string) config('app.url'), '/');

            return $base.'/api/v1/employees/'.$this->id.'/photo/file';
        });
    }

    public static function generateNextEmployeeCode(int $organizationId): string
    {
        $codes = static::query()
            ->where('organization_id', $organizationId)
            ->pluck('employee_code');

        $max = 0;
        foreach ($codes as $code) {
            if (preg_match('/^EMP#?(\d+)$/i', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'EMP#'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public static function composeFullName(?string $first, ?string $middle, ?string $last, ?string $fallback = null): string
    {
        $parts = array_filter([
            trim((string) $first),
            trim((string) $middle),
            trim((string) $last),
        ], fn ($p) => $p !== '');

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return trim((string) $fallback) ?: 'Employee';
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function shift()
    {
        return $this->belongsTo(WorkShift::class, 'shift_id');
    }

    public function position()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function driver()
    {
        return $this->hasOne(Driver::class, 'employee_id');
    }

    public function faceProfile()
    {
        return $this->hasOne(EmployeeFaceProfile::class, 'employee_id');
    }

    public function reportsTo()
    {
        return $this->belongsTo(self::class, 'reports_to_employee_id');
    }

    public function bankAccounts()
    {
        return $this->hasMany(EmployeeBankAccount::class, 'employee_id');
    }

    public function emergencyContacts()
    {
        return $this->hasMany(EmployeeEmergencyContact::class, 'employee_id');
    }

    public function nextOfKin()
    {
        return $this->hasOne(EmployeeNextOfKin::class, 'employee_id');
    }

    public function deductions()
    {
        return $this->hasMany(EmployeeDeduction::class, 'employee_id');
    }

    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class, 'employee_id');
    }
}
