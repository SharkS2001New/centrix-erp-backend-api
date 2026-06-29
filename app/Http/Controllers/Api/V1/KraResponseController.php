<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\KraResponse;
use Illuminate\Http\Request;

class KraResponseController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return KraResponse::class;
    }

    protected function baseQuery(Request $request)
    {
        $query = KraResponse::query();
        $user = $request->user();
        $orgId = $this->access()->organizationId($user, $request);

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        if ($user) {
            $branchId = $this->access()->branchId($user);
            if ($branchId !== null) {
                $query->whereHas('sale', fn ($saleQuery) => $saleQuery->where('branch_id', $branchId));
            }
        }

        return $query;
    }
}
