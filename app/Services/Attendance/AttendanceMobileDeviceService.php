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

    public function normalizeIdentifier(string $identifier, ?string $platform = null): string
    {
        $normalized = mb_strtolower(trim($identifier));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^(android|ios):(.+)$/', $normalized, $matches) === 1) {
            return $matches[1].':'.trim($matches[2]);
        }

        $platform = $platform !== null ? mb_strtolower(trim($platform)) : '';
        if ($platform === 'android' || $platform === 'ios') {
            return $platform.':'.$normalized;
        }

        return $normalized;
    }

    public function assertValidDeviceIdentifier(string $deviceIdentifier): string
    {
        $normalized = $this->normalizeIdentifier($deviceIdentifier);
        if ($normalized === '') {
            throw new InvalidArgumentException('Device identifier is required.');
        }

        $core = preg_match('/^(android|ios):(.+)$/', $normalized, $matches) === 1
            ? $matches[2]
            : $normalized;

        if (strlen($core) < 12) {
            throw new InvalidArgumentException('Device identifier is too short to be trusted.');
        }

        if (preg_match('/^(test|demo|00000000|1234567890|device|attendance)$/i', $core) === 1) {
            throw new InvalidArgumentException('Device identifier is not allowed.');
        }

        return $normalized;
    }

    /** @return list<string> */
    public function identifierLookupKeys(string $identifier, ?string $platform = null): array
    {
        $canonical = $this->normalizeIdentifier($identifier, $platform);
        if ($canonical === '') {
            return [];
        }

        $keys = [$canonical];

        if (preg_match('/^(android|ios):(.+)$/', $canonical, $matches) === 1) {
            $keys[] = $matches[2];
        } else {
            $keys[] = 'android:'.$canonical;
            $keys[] = 'ios:'.$canonical;
        }

        return array_values(array_unique($keys));
    }

    public function findRegisteredDevice(Organization $organization, string $deviceIdentifier): ?AttendanceMobileDevice
    {
        $keys = $this->identifierLookupKeys($deviceIdentifier);
        if ($keys === []) {
            return null;
        }

        return AttendanceMobileDevice::query()
            ->with('branch:id,branch_name,branch_code')
            ->where('organization_id', $organization->id)
            ->whereIn('device_identifier', $keys)
            ->where('is_active', true)
            ->first();
    }

    public function assertRegisteredDevice(Organization $organization, string $deviceIdentifier): AttendanceMobileDevice
    {
        $normalized = $this->assertValidDeviceIdentifier($deviceIdentifier);
        $device = $this->findRegisteredDevice($organization, $normalized);
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
        $normalized = $this->normalizeIdentifier($deviceIdentifier, $platform);
        if ($normalized === '') {
            throw new InvalidArgumentException('Device identifier is required.');
        }

        $this->assertBranchInOrganization($organization, $branchId);

        $lookupKeys = $this->identifierLookupKeys($deviceIdentifier, $platform);
        $existing = AttendanceMobileDevice::query()
            ->where('organization_id', $organization->id)
            ->whereIn('device_identifier', $lookupKeys)
            ->first();

        if ($existing !== null && $existing->device_identifier !== $normalized) {
            $existing->update(['device_identifier' => $normalized]);
        }

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
