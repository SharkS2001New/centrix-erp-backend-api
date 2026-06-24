<?php

namespace App\Services\Attendance;

use App\Models\AttendanceBranchPremises;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Support\AttendanceSchema;
use InvalidArgumentException;

class AttendanceBranchPremisesService
{
    /** @return array<string, mixed>|null */
    public function forBranch(Organization $organization, int $branchId): ?array
    {
        $this->assertBranchInOrganization($organization, $branchId);

        if (! AttendanceSchema::hasBranchPremises()) {
            return null;
        }

        $row = AttendanceBranchPremises::query()
            ->where('organization_id', $organization->id)
            ->where('branch_id', $branchId)
            ->first();

        if (! $row || $row->latitude === null || $row->longitude === null) {
            return null;
        }

        $settings = HrAttendanceSettingsResolver::forOrganization($organization);
        $radius = $row->radius_metres ?? $settings['company_premises_radius_metres'];

        return [
            'branch_id' => (int) $branchId,
            'latitude' => (float) $row->latitude,
            'longitude' => (float) $row->longitude,
            'radius_metres' => max(1, min(500, (float) $radius)),
        ];
    }

    public function hasPremisesForBranch(Organization $organization, int $branchId): bool
    {
        return $this->forBranch($organization, $branchId) !== null;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForOrganization(Organization $organization): array
    {
        $settings = HrAttendanceSettingsResolver::forOrganization($organization);
        $defaultRadius = (float) $settings['company_premises_radius_metres'];

        $branches = Branch::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('branch_name')
            ->get(['id', 'branch_code', 'branch_name']);

        $premisesByBranch = AttendanceSchema::hasBranchPremises()
            ? AttendanceBranchPremises::query()
                ->where('organization_id', $organization->id)
                ->get()
                ->keyBy('branch_id')
            : collect();

        return $branches->map(function (Branch $branch) use ($premisesByBranch, $defaultRadius) {
            $row = $premisesByBranch->get($branch->id);

            return [
                'branch_id' => $branch->id,
                'branch_code' => $branch->branch_code,
                'branch_name' => $branch->branch_name,
                'latitude' => $row?->latitude !== null ? (float) $row->latitude : null,
                'longitude' => $row?->longitude !== null ? (float) $row->longitude : null,
                'radius_metres' => $row?->radius_metres !== null
                    ? (float) $row->radius_metres
                    : $defaultRadius,
                'has_premises_location' => $row?->latitude !== null && $row?->longitude !== null,
                'updated_at' => $row?->updated_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function publicBranchOptions(Organization $organization): array
    {
        $settings = HrAttendanceSettingsResolver::forOrganization($organization);
        if (($settings['attendance_capture_mode'] ?? '') !== 'company_mobile') {
            return [];
        }

        return array_map(
            fn (array $row) => [
                'id' => $row['branch_id'],
                'branch_code' => $row['branch_code'],
                'branch_name' => $row['branch_name'],
                'has_premises_location' => $row['has_premises_location'],
            ],
            $this->listForOrganization($organization),
        );
    }

    public function saveForBranch(
        Organization $organization,
        User $user,
        int $branchId,
        float $latitude,
        float $longitude,
        ?float $radiusMetres = null,
    ): AttendanceBranchPremises {
        $this->assertBranchInOrganization($organization, $branchId);
        if (! AttendanceSchema::hasBranchPremises()) {
            throw new InvalidArgumentException('Attendance premises storage is not available. Run database migrations first.');
        }
        $settings = HrAttendanceSettingsResolver::forOrganization($organization);

        return AttendanceBranchPremises::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'branch_id' => $branchId,
            ],
            [
                'latitude' => round($latitude, 7),
                'longitude' => round($longitude, 7),
                'radius_metres' => $radiusMetres !== null
                    ? max(1, min(500, $radiusMetres))
                    : (float) $settings['company_premises_radius_metres'],
                'updated_by' => $user->id,
                'updated_at' => now(),
            ],
        );
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
