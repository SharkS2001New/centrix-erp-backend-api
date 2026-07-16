<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Employee;
use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;

trait FindsOrganizationEmployee
{
    protected function findOrgEmployee(int|string $id, ?Request $request = null): Employee
    {
        $request ??= request();
        $query = Employee::query()->whereKey($id);
        $user = $request->user();
        if ($user) {
            $access = app(UserAccessService::class);
            $access->scopeOrganization($query, $user, 'organization_id', $request);
            $access->scopeBranchIfLimited($query, $user);
        }

        return $query->firstOrFail();
    }
}
