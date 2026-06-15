<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;

class ErpCapabilitiesController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    /** GET /api/v1/erp/capabilities — what this tenant can use */
    public function show(Request $request)
    {
        $gate = $this->erp->gateForUser($request->user());
        $user = $request->user();

        return response()->json(array_merge($gate->toArray(), [
            'is_admin' => (bool) $user?->is_admin,
            'access_scope' => $user?->access_scope ?? 'org',
            'branch_id' => $user?->branch_id,
            'permissions' => $user
                ? app(UserPermissionService::class)->permissionMapForUser($user)
                : [],
            'allow_org_provisioning' => (bool) $user?->is_admin && config('erp.allow_org_provisioning'),
        ]));
    }

    /** GET /api/v1/erp/profiles — deployment profile definitions (for admin UI) */
    public function profiles()
    {
        return response()->json([
            'profiles' => config('erp.profiles'),
            'modules' => config('erp.modules'),
        ]);
    }
}
