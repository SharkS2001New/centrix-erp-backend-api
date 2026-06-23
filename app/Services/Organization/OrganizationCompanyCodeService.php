<?php

namespace App\Services\Organization;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrganizationCompanyCodeService
{
    public function rename(Organization $org, string $newCode): Organization
    {
        $newCode = strtoupper(trim($newCode));
        if ($newCode === '') {
            throw ValidationException::withMessages([
                'company_code' => ['Company code is required.'],
            ]);
        }

        if (! preg_match('/^[A-Z0-9_-]{2,45}$/', $newCode)) {
            throw ValidationException::withMessages([
                'company_code' => ['Company code must be 2–45 letters, numbers, hyphens, or underscores.'],
            ]);
        }

        $oldCode = (string) $org->company_code;
        if ($oldCode === $newCode) {
            return $org;
        }

        $this->assertCodeAvailable($newCode, (int) $org->id);

        return DB::transaction(function () use ($org, $newCode, $oldCode) {
            $aliases = is_array($org->company_code_aliases) ? $org->company_code_aliases : [];
            if (! in_array($oldCode, $aliases, true)) {
                $aliases[] = $oldCode;
            }

            $org->company_code = $newCode;
            $org->company_code_aliases = array_values(array_unique($aliases));

            $moduleSettings = $org->module_settings ?? [];
            $legacyArchive = is_array($moduleSettings['legacy_archive'] ?? null)
                ? $moduleSettings['legacy_archive']
                : [];

            if (($legacyArchive['enabled'] ?? false) && blank($legacyArchive['legacy_company_code'] ?? null)) {
                $legacyArchive['legacy_company_code'] = $oldCode;
                $moduleSettings['legacy_archive'] = $legacyArchive;
                $org->module_settings = $moduleSettings;
            }

            $org->save();

            return $org->fresh();
        });
    }

    public function assertCodeAvailable(string $code, ?int $exceptOrganizationId = null): void
    {
        $normalized = Organization::normalizeCompanyCodeIdentifier($code);
        if ($normalized === '') {
            throw ValidationException::withMessages([
                'company_code' => ['Company code is required.'],
            ]);
        }

        $query = Organization::query();
        if ($exceptOrganizationId !== null) {
            $query->where('id', '!=', $exceptOrganizationId);
        }

        $conflict = $query->get(['id', 'company_code', 'company_code_aliases'])->first(
            fn (Organization $org) => $this->organizationOwnsIdentifier($org, $normalized),
        );

        if ($conflict) {
            throw ValidationException::withMessages([
                'company_code' => ["Company code [{$code}] is already used by another organization."],
            ]);
        }
    }

    public function organizationOwnsIdentifier(Organization $org, string $normalizedIdentifier): bool
    {
        if (Organization::normalizeCompanyCodeIdentifier((string) $org->company_code) === $normalizedIdentifier) {
            return true;
        }

        foreach ($org->company_code_aliases ?? [] as $alias) {
            if (Organization::normalizeCompanyCodeIdentifier((string) $alias) === $normalizedIdentifier) {
                return true;
            }
        }

        return false;
    }
}
