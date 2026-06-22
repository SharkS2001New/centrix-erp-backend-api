<?php

namespace App\Services\Attendance;

use App\Models\AttendanceMobileDevice;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeClockSession;
use App\Models\EmployeeFaceProfile;
use App\Models\Organization;
use App\Support\AttendanceHours;
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
        protected AttendanceMobileDeviceService $mobileDevices,
        protected AttendanceBranchPremisesService $branchPremises,
    ) {}

    public function resolveOrganization(string $companyCode): Organization
    {
        $organization = Organization::query()
            ->whereRaw('UPPER(company_code) = ?', [strtoupper(trim($companyCode))])
            ->first();

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

        $employees = Employee::query()
            ->where('organization_id', $organization->id)
            ->where('employment_status', 'active')
            ->where('is_active', true)
            ->when($device->branch_id, fn (Builder $builder) => $builder->where('branch_id', $device->branch_id))
            ->when($query, function (Builder $builder) use ($query) {
                $term = '%'.trim($query).'%';
                $builder->where(function (Builder $inner) use ($term) {
                    $inner->where('full_name', 'like', $term)
                        ->orWhere('employee_code', 'like', $term);
                });
            })
            ->orderBy('full_name')
            ->limit(max(1, min(50, $limit)))
            ->get(['id', 'full_name', 'employee_code', 'branch_id']);

        return $employees->map(function (Employee $employee) {
            $hasFaceProfile = EmployeeFaceProfile::query()
                ->where('employee_id', $employee->id)
                ->exists();
            $openSession = $this->openSessionForEmployee($employee->id);

            return [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'branch_id' => $employee->branch_id,
                'face_enrolled' => $hasFaceProfile,
                'has_open_session' => $openSession !== null,
                'open_session_id' => $openSession?->id,
            ];
        })->values()->all();
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

        return [
            'employee' => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_code' => $employee->employee_code,
                'face_enrolled' => $hasFaceProfile,
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

        $photo = $data['photo'] ?? null;
        if (! $photo instanceof UploadedFile) {
            throw new InvalidArgumentException('A face photo is required.');
        }

        $embedding = $this->faceVerification->parseEmbedding($data['face_embedding'] ?? null);
        $geo = $this->premises->assertWithinPremises(
            $organization,
            (int) $device->branch_id,
            $settings,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

        $face = $this->faceVerification->verifyOrEnroll(
            $employee,
            $embedding,
            $photo,
            (float) $settings['company_face_match_threshold'],
            $this->trimDeviceId($data['device_identifier'] ?? null),
        );

        return DB::transaction(function () use (
            $employee,
            $organization,
            $data,
            $photo,
            $geo,
            $face,
        ) {
            $photoPath = $photo->store(
                "company-mobile-attendance/{$organization->id}/{$employee->id}/clock-in",
                'public',
            );

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
                'clock_in_photo_path' => $photoPath,
                'clock_in_face_match_score' => $face['score'],
                'clock_in_geofence_distance_metres' => $geo['distance_metres'],
            ]);

            return [
                'session' => $session->load('employee'),
                'face_enrolled' => (bool) $face['enrolled'],
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

        $photo = $data['photo'] ?? null;
        if (! $photo instanceof UploadedFile) {
            throw new InvalidArgumentException('A face photo is required.');
        }

        $embedding = $this->faceVerification->parseEmbedding($data['face_embedding'] ?? null);
        $geo = $this->premises->assertWithinPremises(
            $organization,
            (int) $device->branch_id,
            $settings,
            (float) $data['latitude'],
            (float) $data['longitude'],
        );

        $face = $this->faceVerification->verifyOrEnroll(
            $employee,
            $embedding,
            $photo,
            (float) $settings['company_face_match_threshold'],
            $this->trimDeviceId($data['device_identifier'] ?? null),
        );

        $photoPath = $photo->store(
            "company-mobile-attendance/{$organization->id}/{$employee->id}/clock-out",
            'public',
        );

        return DB::transaction(function () use ($session, $employee, $data, $photoPath, $face, $geo) {
            $out = now();
            $session->fill([
                'clock_out_at' => $out,
                'clock_out_latitude' => (float) $data['latitude'],
                'clock_out_longitude' => (float) $data['longitude'],
                'clock_out_address' => $this->trimAddress($data['address'] ?? null),
                'clock_out_photo_path' => $photoPath,
                'clock_out_face_match_score' => $face['score'],
                'clock_out_geofence_distance_metres' => $geo['distance_metres'],
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
                'face_enrolled' => $face['enrolled'],
            ];
        });
    }

    /** @param  array<string, mixed>  $filters */
    public function paginateSessions(Organization $organization, array $filters = []): LengthAwarePaginator
    {
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

        return $query->paginate(max(1, min(200, (int) ($filters['per_page'] ?? 25))));
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

        if ($device?->branch_id && (int) $employee->branch_id !== (int) $device->branch_id) {
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
