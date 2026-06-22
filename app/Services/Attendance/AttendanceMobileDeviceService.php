<?php

namespace App\Services\Attendance;

use App\Models\AttendanceMobileDevice;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use InvalidArgumentException;

class AttendanceMobileDeviceService
{
    public function __construct(
        protected AttendanceBranchPremisesService $branchPremises,
    ) {}

    public function normalizeIdentifier(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    public function findRegisteredDevice(Organization $organization, string $deviceIdentifier): ?AttendanceMobileDevice
    {
        $normalized = $this->normalizeIdentifier($deviceIdentifier);
        if ($normalized === '') {
            return null;
        }

        return AttendanceMobileDevice::query()
            ->with('branch:id,branch_name,branch_code')
            ->where('organization_id', $organization->id)
            ->where('device_identifier', $normalized)
            ->where('is_active', true)
            ->first();
    }

    public function assertRegisteredDevice(Organization $organization, string $deviceIdentifier): AttendanceMobileDevice
    {
        $device = $this->findRegisteredDevice($organization, $deviceIdentifier);
        if (! $device) {
            throw new InvalidArgumentException('This phone is not registered for company attendance.');
        }

        return $device;
    }

    /** @return array<string, mixed> */
    public function deviceStatus(
        Organization $organization,
        ?string $deviceIdentifier,
        ?int $pendingBranchId = null,
    ): array {
        $settings = HrAttendanceSettingsResolver::forOrganization($organization);
        $featureEnabled = ($settings['attendance_capture_mode'] ?? '') === 'company_mobile';

        if (! $deviceIdentifier) {
            $branchId = $pendingBranchId;
            $hasPremises = $branchId
                ? $this->branchPremises->hasPremisesForBranch($organization, $branchId)
                : $this->organizationHasAnyPremises($organization);

            return [
                'feature_enabled' => $featureEnabled,
                'has_premises_location' => $hasPremises,
                'device_registered' => false,
                'attendance_phone' => false,
                'branch_id' => $branchId,
            ];
        }

        $device = $this->findRegisteredDevice($organization, $deviceIdentifier);
        $branchId = $device?->branch_id ?? $pendingBranchId;
        $hasPremises = $branchId
            ? $this->branchPremises->hasPremisesForBranch($organization, (int) $branchId)
            : false;

        return [
            'feature_enabled' => $featureEnabled,
            'has_premises_location' => $hasPremises,
            'device_registered' => $device !== null,
            'attendance_phone' => $device !== null && $featureEnabled && $hasPremises,
            'device_label' => $device?->device_label,
            'branch_id' => $device?->branch_id,
            'branch_name' => $device?->branch?->branch_name,
            'branch_code' => $device?->branch?->branch_code,
        ];
    }

    public function register(
        Organization $organization,
        User $user,
        string $deviceIdentifier,
        int $branchId,
        ?string $label = null,
        ?string $platform = null,
    ): AttendanceMobileDevice {
        $normalized = $this->normalizeIdentifier($deviceIdentifier);
        if ($normalized === '') {
            throw new InvalidArgumentException('Device identifier is required.');
        }

        $this->assertBranchInOrganization($organization, $branchId);

        return AttendanceMobileDevice::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'device_identifier' => $normalized,
            ],
            [
                'branch_id' => $branchId,
                'device_label' => $label ? mb_substr(trim($label), 0, 120) : null,
                'platform' => $platform ? mb_substr(trim($platform), 0, 32) : null,
                'is_active' => true,
                'registered_by' => $user->id,
            ],
        );
    }

    public function validateBranch(Organization $organization, int $branchId): void
    {
        $this->assertBranchInOrganization($organization, $branchId);
    }

    protected function organizationHasAnyPremises(Organization $organization): bool
    {
        foreach ($this->branchPremises->listForOrganization($organization) as $row) {
            if ($row['has_premises_location']) {
                return true;
            }
        }

        return false;
    }

    protected function assertBranchInOrganization(Organization $organization, int $branchId): void
    {
        $exists = Branch::query()
            ->where('organization_id', $organization->id)
            ->whereKey($branchId)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException('Branch not found in this organization.');
        }
    }
}
