<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\ErpContext;
use App\Services\Mobile\ManagerAppModuleAccessService;
use Illuminate\Http\Request;

class MobileManagerAdminController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected ManagerAppModuleAccessService $managerApp,
        protected UserAccessService $access,
    ) {}

    public function indexUsers(Request $request)
    {
        $this->assertManagerAccess($request);

        return app(UserController::class)->index($request);
    }

    public function showUser(Request $request, string $user)
    {
        $this->assertManagerAccess($request);

        return app(UserController::class)->show($request, $user);
    }

    public function storeUser(Request $request)
    {
        $this->assertManagerAccess($request);

        return app(UserController::class)->store($request);
    }

    public function updateUser(Request $request, string $user)
    {
        $this->assertManagerAccess($request);

        return app(UserController::class)->update($request, $user);
    }

    public function destroyUser(Request $request, string $user)
    {
        $this->assertManagerAccess($request);

        return app(UserController::class)->destroy($request, $user);
    }

    public function indexRoles(Request $request)
    {
        $this->assertManagerAccess($request);

        return app(RoleController::class)->index($request);
    }

    public function permissionMatrix(Request $request)
    {
        $this->assertManagerAccess($request);

        return app(RoleController::class)->permissionMatrix($request);
    }

    public function rolePermissions(Request $request, string $role)
    {
        $this->assertManagerAccess($request);

        return app(RoleController::class)->permissions($request, $role);
    }

    public function syncRolePermissions(Request $request, string $role)
    {
        $this->assertManagerAccess($request);

        return app(RoleController::class)->syncPermissions($request, $role);
    }

    public function branches(Request $request)
    {
        $this->assertManagerAccess($request);
        $orgId = (int) ($this->access->organizationId($request->user(), $request) ?? 0);
        abort_if($orgId <= 0, 403, 'Organization context is required.');

        $rows = Branch::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('branch_name')
            ->get(['id', 'branch_code', 'branch_name', 'branch_type']);

        return response()->json(['data' => $rows]);
    }

    protected function assertManagerAccess(Request $request): void
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $this->managerApp->assertManagerAccess($user, $gate);
    }
}
