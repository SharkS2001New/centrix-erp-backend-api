<?php

namespace App\Support;

use App\Models\Branch;

class OrganizationIdResolver
{
    /** @var array<int, int|null> */
    private static array $branchOrgCache = [];

    public static function forBranch(?int $branchId): ?int
    {
        if (! $branchId) {
            return null;
        }

        if (array_key_exists($branchId, self::$branchOrgCache)) {
            return self::$branchOrgCache[$branchId];
        }

        $orgId = Branch::query()->whereKey($branchId)->value('organization_id');
        self::$branchOrgCache[$branchId] = $orgId !== null ? (int) $orgId : null;

        return self::$branchOrgCache[$branchId];
    }

    public static function requireForBranch(int $branchId): int
    {
        $orgId = self::forBranch($branchId);
        if (! $orgId) {
            throw new \InvalidArgumentException("Branch {$branchId} has no organization.");
        }

        return $orgId;
    }
}
