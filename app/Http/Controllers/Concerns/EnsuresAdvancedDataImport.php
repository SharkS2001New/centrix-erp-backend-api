<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;

trait EnsuresAdvancedDataImport
{
    protected function ensureAdvancedDataImport(Request $request): void
    {
        abort_unless($request->user(), 403);

        $org = app(ErpContext::class)->resolveOrganization($request);

        $gate = app(CapabilityGate::class)->forOrganization($org);
        abort_unless(
            $gate->advancedDataImportPlatformEnabled(),
            403,
            'Advanced data import is not enabled for this organization.',
        );
    }
}
