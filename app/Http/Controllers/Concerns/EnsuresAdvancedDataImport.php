<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use Illuminate\Http\Request;

trait EnsuresAdvancedDataImport
{
    protected function ensureAdvancedDataImport(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && $user->is_admin, 403, 'Only organization administrators can import data.');

        $orgId = (int) $user->organization_id;
        abort_unless($orgId > 0, 403);

        $org = $user->organization ?? Organization::query()->find($orgId);
        abort_unless($org, 403);

        $gate = app(CapabilityGate::class)->forOrganization($org);
        abort_unless(
            $gate->advancedDataImportPlatformEnabled(),
            403,
            'Advanced data import is not enabled for this organization.',
        );
    }
}
