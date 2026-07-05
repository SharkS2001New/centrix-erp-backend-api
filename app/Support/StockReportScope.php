<?php

namespace App\Support;

use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReportScope
{
    public static function resolveBranchId(Request $request, int $organizationId): int
    {
        $branchId = $request->filled('branch_id')
            ? (int) $request->input('branch_id')
            : null;

        if ($branchId === null && $request->user()) {
            $branchId = app(UserAccessService::class)->branchId($request->user());
        }

        if ($branchId !== null && $branchId > 0) {
            if ($request->user()) {
                app(UserAccessService::class)->assertBranchInOrganization(
                    $request->user(),
                    $branchId,
                    $request,
                    'You do not have access to this branch.',
                );
            }

            return $branchId;
        }

        $branchCount = (int) DB::table('branches')
            ->where('organization_id', $organizationId)
            ->count();

        if ($branchCount > 1) {
            abort(response()->json([
                'message' => 'branch_id is required for organizations with multiple branches.',
            ], 422));
        }

        if ($branchCount === 0) {
            abort(response()->json([
                'message' => 'No branches found for this organization.',
            ], 422));
        }

        return (int) DB::table('branches')
            ->where('organization_id', $organizationId)
            ->value('id');
    }
}
