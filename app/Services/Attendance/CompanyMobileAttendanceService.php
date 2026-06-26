<?php

namespace App\Services\Attendance;

use App\Models\EmployeeFingerprintProfile;
use App\Models\AttendanceMobileDevice;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeClockSession;
use App\Models\EmployeeFaceProfile;
use App\Models\Organization;
use App\Support\AttendanceHours;
use App\Support\AttendanceSchema;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompanyMobileAttendanceService
{
    public function __construct(
        protected CompanyPremisesLocationService $premises,
        protected EmployeeFaceVerificationService $faceVerification,
        protected EmployeeFingerprintVerificationService $fingerprintVerification,
        protected AttendanceMobileDeviceService $mobileDevices,
        protected AttendanceBranchPremisesService $branchPremises,
    ) {}

    public function resolveOrganization(string $companyCode): Organization
    {
        $organization = Organization::findByCompanyCodeIdentifier($companyCode);

        if (! $organization) {
            throw new InvalidArgumentException('Organization not found for this company code.');
        }

        return $organization;
    }

    /** @return array<string, mixed> */
    public function publicConfig(Organization $organization, ?string $deviceIdentifier = null): array
    {
        $settings = HrAttendanceSettingsResolver::forOrganization($organization);
        $deviceStatus = $this->mobileDevices->deviceStatus($organization, $deviceIdentifier);
        $branchPremises = null;
        if (! empty($deviceStatus['branch_id'])) {
            $branchPremises = $this->branchPremises->forBranch($organization, (int) $deviceStatus['branch_id']);
        }

        return array_merge(
            HrAttendanceSettingsResolver::companyMobilePublicConfig($settings, $branchPremises),
            $deviceStatus,
            [
                'organization_name' => $organization->org_name,
                'company_code' => $organization->company_code,
            ],
        );
    }

    /** @return array<string, mixed> */
    public function deviceStatus(
        Organization $organization,
        ?string $deviceIdentifier,
        ?int $pendingBranchId = null,
    ): array {
        return array_merge(
            $this->mobileDevices->deviceStatus($organization, $deviceIdentifier, $pendingBranchId),
            [
                'organization_name' => $organization->org_name,
                'company_code' => $organization->company_code,
            ],
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listBranches(Organization $organization): array
    {
        $this->assertFeatureEnabled($organization);

        return $this->branchPremises->publicBranchOptions($organization);
    }

    /** @return array<int, array<string, mixed>> */
    public function searchEmployees(Organization $organization, ?string $query, int $limit, string $deviceIdentifier): array
    {
        $device = $this->assertEnabledWithRegisteredDevice($organization, $deviceIdentifier);
        $normalizedQuery = trim((string) ($query ?? ''));

        if ($normalizedQuery !== '' && mb_strlen($normalizedQuery) < 3) {
            throw new InvalidArgumentException('Enter at least 3 characters to search employees.');
        }

        $builder = $this->attendanceEmployeeQuery($organization, $device, $normalizedQuery);
        $columns = [
            'id',
            'full_name',
            'employee_code',
            'first_name',
            'middle_name',
            'last_name',
            'payroll_number',
            'branch_id',
        ];

        if ($normalizedQuery === '') {
            $employees = $builder
                ->orderBy('full_name')
                ->limit(max(1, min(50, $limit)))
                ->get($columns);
        } else {
            $employees = $this->applyEmployeeSearchTerm($builder, $normalizedQuery)
                ->limit(100)
                ->get($columns);

            $employees = $this->rankEmployeesForSearch($employees, $normalizedQuery)
                ->take(max(1, min(50, $limit)))
                ->values();
        }

        return $employees
            ->map(fn (Employee $employee) => $this->serializeEmployeeOption($employee))
            ->values()
            ->all();
    }

    protected function attendanceEmployeeQuery(
        Organization $organization,
        AttendanceMobileDevice $device,
        ?string $searchQuery = null,
    ): Builder {
        $builder = Employee::query()
            ->where('organization_id', $organization->id)
            ->where(function (Builder $query) {
                $query->where('is_active', true)
                    ->orWhere('is_active', 1)
                    ->orWhereNull('is_active');
            })
            ->where(function (Builder $query) {
                $query->where('employment_status', 'active')
                    ->orWhereNull('employment_status');
            });

        $normalizedSearch = trim((string) ($searchQuery ?? ''));
        if ($normalizedSearch === '' && $device->branch_id) {
            $builder->where(function (Builder $branchQuery) use ($device) {
                $branchQuery->where('branch_id', (int) $device->branch_id)
                    ->orWhereNull('branch_id');
            });
        }

        return $builder;
    }

    protected function applyEmployeeSearchTerm(Builder $builder, string $query): Builder
    {
        $needle = mb_strtolower(trim($query));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
        $term = '%'.$escaped.'%';
        $prefix = mb_substr($needle, 0, min(3, mb_strlen($needle)));
        $searchColumns = [
            'full_name',
            'first_name',
            'middle_name',
            'last_name',
            'employee_code',
            'payroll_number',
            'email',
            'personal_email',
            'job_title',
            'national_id',
        ];
        $prefixColumns = [
            'full_name',
            'first_name',
            'middle_name',
            'last_name',
            'employee_code',
            'payroll_number',
        ];

        return $builder->where(function (Builder $inner) use ($searchColumns, $prefixColumns, $term, $prefix) {
            foreach ($searchColumns as $column) {
                $inner->orWhere($column, 'like', $term);
            }

            if ($prefix !== '') {
                foreach ($prefixColumns as $column) {
                    $inner->orWhereRaw(
                        'LEFT(LOWER('.$column.'), ?) = ?',
                        [mb_strlen($prefix), $prefix],
                    );
                }

                $inner->orWhereRaw(
                    'LOWER(CONCAT_WS(" ", first_name, middle_name, last_name)) LIKE ?',
                    ['%'.$prefix.'%'],
                );
            }
        });
    }

    /** @param  \Illuminate\Support\Collection<int, Employee>  $employees */
    protected function rankEmployeesForSearch($employees, string $query)
    {
        $needle = mb_strtolower(trim($query));

        return $employees
            ->map(function (Employee $employee) use ($needle) {
                return [
                    'employee' => $employee,
                    'score' => max(1, $this->employeeSearchScore($employee, $needle)),
                ];
            })
            ->sortByDesc('score')
            ->pluck('employee');
    }

    protected function employeeSearchScore(Employee $employee, string $needle): int
    {
        $prefix = mb_substr($needle, 0, min(3, mb_strlen($needle)));
        $code = mb_strtolower(trim((string) ($employee->employee_code ?? '')));
        $payroll = mb_strtolower(trim((string) ($employee->payroll_number ?? '')));
        $fullName = mb_strtolower(trim((string) ($employee->full_name ?? '')));
        $firstName = mb_strtolower(trim((string) ($employee->first_name ?? '')));
        $middleName = mb_strtolower(trim((string) ($employee->middle_name ?? '')));
        $lastName = mb_strtolower(trim((string) ($employee->last_name ?? '')));
        $combinedName = trim($firstName.' '.$middleName.' '.$lastName);

        if ($code !== '' && $code === $needle) {
            return 1000;
        }
        if ($payroll !== '' && $payroll === $needle) {
            return 950;
        }
        if ($fullName !== '' && str_starts_with($fullName, $needle)) {
            return 900;
        }
        if ($combinedName !== '' && str_starts_with($combinedName, $needle)) {
            return 850;
        }
        if ($code !== '' && str_starts_with($code, $needle)) {
            return 800;
        }
        if ($firstName !== '' && str_starts_with($firstName, $needle)) {
            return 750;
        }
        if ($lastName !== '' && str_starts_with($lastName, $needle)) {
            return 700;
        }
        if ($fullName !== '' && str_contains($fullName, $needle)) {
            return 500;
        }
        if ($combinedName !== '' && str_contains($combinedName, $needle)) {
            return 450;
        }
        if ($code !== '' && str_contains($code, $needle)) {
            return 400;
        }
        if ($payroll !== '' && str_contains($payroll, $needle)) {
            return 350;
        }
        if ($firstName !== '' && str_contains($firstName, $needle)) {
            return 300;
        }
        if ($middleName !== '' && str_contains($middleName, $needle)) {
            return 275;
        }
        if ($lastName !== '' && str_contains($lastName, $needle)) {
            return 250;
        }
        if ($prefix !== '' && $lastName !== '' && str_starts_with($lastName, $prefix)) {
            return 200;
        }
        if ($prefix !== '' && $firstName !== '' && str_starts_with($firstName, $prefix)) {
            return 175;
        }
        if ($prefix !== '' && $fullName !== '' && str_contains($fullName, $prefix)) {
            return 150;
        }

        return 0;
    }

    /** @return array<string, mixed> */
    protected function serializeEmployeeOption(Employee $employee): array
    {
        $hasFaceProfile = AttendanceSchema::hasFaceProfiles()
            && EmployeeFaceProfile::query()
                ->where('employee_id', $employee->id)
                ->exists();
        $hasFingerprintProfile = AttendanceSchema::hasFingerprintProfiles()
            && EmployeeFingerprintProfile::query()
                ->where('employee_id', $employee->id)
                ->exists();
        $openSession = $this->openSessionForEmployee($employee->id);

        return [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'employee_code' => $employee->employee_code,
            'branch_id' => $employee->branch_id,
            'face_enrolled' => $hasFaceProfile,
            'fingerprint_enrolled' => $hasFingerprintProfile,
            'has_open_session' => $openSession !== null,
            'open_session_id' => $openSession?->id,
        ];
    }

    /** @return array<string, mixed> */
    public function employeeSessionState(Organization $organization, int $employeeId, string $deviceIdentifier): array
    {
        $device = $this->assertEnabledWithRegisteredDevice($organization, $deviceIdentifier);
        $employee = $this->findEmployee($organization, $employeeId, $device);
        $openSession = $this->openSessionForEmployee($employee->id);
        $hasFaceProfile = EmployeeFaceProfile::query()
            ->where('employee_id', $employee->id)
            ->exists();
        $hasFingerprintProfile = EmployeeFingerprintProfile::query()
            ->where('employee_id', $employee->id)
            ->exists();

        return [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'face_enrolled' => $hasFaceProfile,
                'fingerprint_enrolled' => $hasFingerprintProfile,
            ],
            'session' => $openSession ? $this->serializeSession($openSession) : null,
            'next_action' => $openSession ? 'clock_out' : 'clock_in',
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function clockIn(Organization $organization, array $data): array
    {
        $settings = $this->settings($organization);
        $deviceIdentifier = (string) ($data['device_identifier'] ?? '');
        $device = $this->assertEnabledWithRegisteredDevice($organization, $deviceIdentifier);
        $employee = $this->findEmployee($organization, (int) $data['employee_id'], $device);

        try {
            app(AttendanceDayPolicy::class)->assertCanClockIn($employee);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        if ($this->openSessionForEmployee($employee->id)) {
            throw new InvalidArgumentException('Employee already has an open shift session.');
        }

        $geo = $this->premises->assertWithinPremises(
            $organization,
            (int) $device->branch_id,
            $settings,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

        $verification = $this->resolveVerification($settings, $employee, $data, 'clock-in');

        return DB::transaction(function () use (
            $employee,
            $organization,
            $data,
            $geo,
            $verification,
        ) {
            $session = EmployeeClockSession::create([
                'employee_id' => $employee->id,
                'organization_id' => $employee->organization_id,
                'branch_id' => $employee->branch_id,
                'source' => 'company_mobile',
                'clock_in_at' => now(),
                'device_identifier' => $this->trimDeviceId($data['device_identifier'] ?? null),
                'clock_in_latitude' => (float) $data['latitude'],
                'clock_in_longitude' => (float) $data['longitude'],
                'clock_in_address' => $this->trimAddress($data['address'] ?? null),
                'clock_in_photo_path' => $verification['photo_path'],
                'clock_in_face_match_score' => $verification['face_score'],
                'clock_in_geofence_distance_metres' => $geo['distance_metres'],
                'clock_in_verification_method' => $verification['method'],
            ]);

            return [
                'session' => $session->load('employee'),
                'face_enrolled' => (bool) $verification['face_enrolled'],
                'fingerprint_enrolled' => (bool) $verification['fingerprint_enrolled'],
            ];
        });
    }

    /** @param  array<string, mixed>  $data */
    public function clockOut(Organization $organization, array $data): array
    {
        $settings = $this->settings($organization);
        $deviceIdentifier = (string) ($data['device_identifier'] ?? '');
        $device = $this->assertEnabledWithRegisteredDevice($organization, $deviceIdentifier);
        $employee = $this->findEmployee($organization, (int) $data['employee_id'], $device);

        $session = $this->openSessionForEmployee($employee->id);
        if (! $session) {
            throw new InvalidArgumentException('No open shift session found for this employee.');
        }

        $geo = $this->premises->assertWithinPremises(
            $organization,
            (int) $device->branch_id,
            $settings,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

        $verification = $this->resolveVerification($settings, $employee, $data, 'clock-out');

        return DB::transaction(function () use ($session, $employee, $data, $verification, $geo) {
            $out = now();
            $session->fill([
                'clock_out_at' => $out,
                'clock_out_latitude' => (float) $data['latitude'],
                'clock_out_longitude' => (float) $data['longitude'],
                'clock_out_address' => $this->trimAddress($data['address'] ?? null),
                'clock_out_photo_path' => $verification['photo_path'],
                'clock_out_face_match_score' => $verification['face_score'],
                'clock_out_geofence_distance_metres' => $geo['distance_metres'],
                'clock_out_verification_method' => $verification['method'],
            ]);
            $session->save();

            $in = Carbon::parse($session->clock_in_at);
            $attendanceDate = $in->toDateString();
            $checkIn = $in->format('H:i:s');
            $checkOut = $out->format('H:i:s');
            $hours = AttendanceHours::fromTimeStrings($checkIn, $checkOut);
            $policy = app(AttendanceDayPolicy::class);
            $eval = $policy->evaluate($employee->loadMissing('shift'), $attendanceDate);
            $status = $eval['should_work'] ? 'present' : $eval['suggested_status'];

            $attendance = EmployeeAttendance::query()->updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'attendance_date' => $attendanceDate,
                ],
                [
                    'organization_id' => $employee->organization_id,
                    'branch_id' => $session->branch_id ?? $employee->branch_id,
                    'check_in' => $status === 'present' ? $checkIn : null,
                    'check_out' => $status === 'present' ? $checkOut : null,
                    'status' => $status,
                    'source' => 'company_mobile',
                    'device_identifier' => $session->device_identifier,
                    'hours_worked' => $status === 'present' ? $hours : 0,
                    'notes' => $eval['reason'],
                ],
            );

            $session->attendance_id = $attendance->id;
            $session->save();

            return [
                'session' => $this->serializeSession($session->fresh(['employee'])),
                'attendance' => $attendance,
                'face_enrolled' => (bool) $verification['face_enrolled'],
                'fingerprint_enrolled' => (bool) $verification['fingerprint_enrolled'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $data
     * @return array{method: string, photo_path: ?string, face_score: ?float, face_enrolled: bool, fingerprint_enrolled: bool}
     */
    protected function resolveVerification(array $settings, Employee $employee, array $data, string $action): array
    {
        $method = (string) ($data['verification_method'] ?? 'face');
        HrAttendanceSettingsResolver::assertVerificationMethodAllowed($settings, $method);

        if ($method === 'fingerprint') {
            $encodedTemplate = trim((string) ($data['fingerprint_template'] ?? ''));
            $templateVector = $this->fingerprintVerification->parseTemplate($encodedTemplate);
            $fingerprint = $this->fingerprintVerification->verifyOrEnroll(
                $employee,
                $templateVector,
                $encodedTemplate,
                (float) $settings['company_fingerprint_match_threshold'],
                $this->trimDeviceId($data['device_identifier'] ?? null),
                isset($data['scanner_model']) ? (string) $data['scanner_model'] : null,
                (bool) ($settings['company_fingerprint_auto_enroll_on_clock'] ?? true),
            );

            return [
                'method' => 'fingerprint',
                'photo_path' => null,
                'face_score' => $fingerprint['score'],
                'face_enrolled' => false,
                'fingerprint_enrolled' => (bool) $fingerprint['enrolled'],
            ];
        }

        $photo = $data['photo'] ?? null;
        if (! $photo instanceof UploadedFile) {
            throw new InvalidArgumentException('A face photo is required.');
        }

        $embedding = $this->faceVerification->parseEmbedding($data['face_embedding'] ?? null);
        $face = $this->faceVerification->verifyOrEnroll(
            $employee,
            $embedding,
            $photo,
            (float) $settings['company_face_match_threshold'],
            $this->trimDeviceId($data['device_identifier'] ?? null),
        );

        $photoPath = $photo->store(
            "company-mobile-attendance/{$employee->organization_id}/{$employee->id}/{$action}",
            'public',
        );

        return [
            'method' => 'face',
            'photo_path' => $photoPath,
            'face_score' => $face['score'],
            'face_enrolled' => (bool) $face['enrolled'],
            'fingerprint_enrolled' => false,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    public function paginateSessions(Organization $organization, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 25)));

        if (! AttendanceSchema::hasCompanyMobileSessions()) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        $query = EmployeeClockSession::query()
            ->with('employee:id,full_name,employee_code')
            ->where('organization_id', $organization->id)
            ->where('source', 'company_mobile')
            ->orderByDesc('clock_in_at');

        if (! empty($filters['from_date'])) {
            $query->whereDate('clock_in_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('clock_in_at', '<=', $filters['to_date']);
        }
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
        if (array_key_exists('open_only', $filters) && filter_var($filters['open_only'], FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('clock_out_at');
        }

        return $query->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function serializeSession(EmployeeClockSession $session, bool $includePhotos = false): array
    {
        $employee = $session->relationLoaded('employee') ? $session->employee : null;
        $seconds = 0;
        if ($session->clock_in_at) {
            $end = $session->clock_out_at ?? now();
            $seconds = max(0, $session->clock_in_at->diffInSeconds($end));
        }

        $payload = [
            'id' => $session->id,
            'employee_id' => $session->employee_id,
            'employee_name' => $employee?->full_name,
            'employee_code' => $employee?->employee_code,
            'source' => $session->source,
            'clock_in_at' => $session->clock_in_at?->toIso8601String(),
            'clock_out_at' => $session->clock_out_at?->toIso8601String(),
            'is_open' => $session->clock_out_at === null,
            'clock_in_latitude' => $session->clock_in_latitude,
            'clock_in_longitude' => $session->clock_in_longitude,
            'clock_out_latitude' => $session->clock_out_latitude,
            'clock_out_longitude' => $session->clock_out_longitude,
            'clock_in_address' => $session->clock_in_address,
            'clock_out_address' => $session->clock_out_address,
            'clock_in_face_match_score' => $session->clock_in_face_match_score,
            'clock_out_face_match_score' => $session->clock_out_face_match_score,
            'clock_in_geofence_distance_metres' => $session->clock_in_geofence_distance_metres,
            'clock_out_geofence_distance_metres' => $session->clock_out_geofence_distance_metres,
            'work_seconds' => $seconds,
            'work_label' => sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        ];

        if ($includePhotos) {
            $payload['clock_in_photo_url'] = $this->photoUrl($session->clock_in_photo_path);
            $payload['clock_out_photo_url'] = $this->photoUrl($session->clock_out_photo_path);
        }

        return $payload;
    }

    protected function assertEnabledWithRegisteredDevice(Organization $organization, string $deviceIdentifier): AttendanceMobileDevice
    {
        $this->assertFeatureEnabled($organization);
        $device = $this->mobileDevices->assertRegisteredDevice($organization, $deviceIdentifier);

        if (! $device->branch_id) {
            throw new InvalidArgumentException('This attendance phone is not assigned to a branch.');
        }

        if (! $this->branchPremises->hasPremisesForBranch($organization, (int) $device->branch_id)) {
            throw new InvalidArgumentException('Company premises location must be configured for this branch before marking attendance.');
        }

        return $device;
    }

    protected function assertFeatureEnabled(Organization $organization): void
    {
        $settings = $this->settings($organization);
        if (($settings['attendance_capture_mode'] ?? 'clock_device') !== 'company_mobile') {
            throw new InvalidArgumentException('Company mobile attendance is not enabled for this organization.');
        }
    }

    protected function assertEnabled(Organization $organization): void
    {
        $this->assertFeatureEnabled($organization);
    }

    /** @return array<string, mixed> */
    protected function settings(Organization $organization): array
    {
        return HrAttendanceSettingsResolver::forOrganization($organization);
    }

    protected function findEmployee(
        Organization $organization,
        int $employeeId,
        ?AttendanceMobileDevice $device = null,
    ): Employee {
        $employee = Employee::with('shift')->find($employeeId);
        if (! $employee || (int) $employee->organization_id !== (int) $organization->id) {
            throw new InvalidArgumentException('Employee not found.');
        }

        if ($device?->branch_id && $employee->branch_id !== null && (int) $employee->branch_id !== (int) $device->branch_id) {
            throw new InvalidArgumentException('Employee does not belong to this attendance phone branch.');
        }

        return $employee;
    }

    protected function openSessionForEmployee(int $employeeId): ?EmployeeClockSession
    {
        return EmployeeClockSession::query()
            ->where('employee_id', $employeeId)
            ->where('source', 'company_mobile')
            ->whereNull('clock_out_at')
            ->orderByDesc('clock_in_at')
            ->first();
    }

    protected function photoUrl(?string $path): ?string
    {
        return $path ? url('storage/'.$path) : null;
    }

    protected function trimAddress(mixed $value): ?string
    {
        $address = trim((string) ($value ?? ''));

        return $address === '' ? null : mb_substr($address, 0, 500);
    }

    protected function trimDeviceId(mixed $value): ?string
    {
        $deviceId = trim((string) ($value ?? ''));

        return $deviceId === '' ? null : mb_substr($deviceId, 0, 100);
    }
}
