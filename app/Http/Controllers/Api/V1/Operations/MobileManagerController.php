<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Mobile\ManagerAppModuleAccessService;
use App\Models\UserDeviceToken;
use App\Services\Mobile\UserDeviceTokenService;
use App\Services\Mobile\ManagerReportCatalogService;
use App\Services\Sales\MobileManagerService;
use Illuminate\Http\Request;

class MobileManagerController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected MobileManagerService $manager,
        protected ManagerAppModuleAccessService $managerApp,
        protected UserPermissionService $permissions,
        protected ManagerReportCatalogService $reportCatalog,
        protected UserDeviceTokenService $deviceTokens,
        protected UserAccessService $access,
    ) {}

    /** GET /manager/dashboard — executive snapshot for Centrix Manager app. */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $this->managerApp->assertManagerAccess($user, $gate);

        return response()->json(
            $this->manager->dashboard($user, $gate, $request->only(['from_date', 'to_date', 'branch_id'])),
        );
    }

    /** GET /manager/branches — branches the signed-in user may filter reports by. */
    public function branches(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $this->managerApp->assertManagerAccess($user, $gate);

        $orgId = (int) ($this->access->organizationId($user, $request) ?? 0);
        abort_if($orgId <= 0, 403, 'Organization context is required.');

        $query = Branch::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('branch_name');

        if (($user->access_scope ?? 'org') === 'branch' && $user->branch_id) {
            $query->whereKey($user->branch_id);
        }

        return response()->json([
            'data' => $query->get(['id', 'branch_code', 'branch_name', 'branch_type']),
        ]);
    }

    /** GET /manager/reports/catalog — permission-filtered report hub for Centrix Manager. */
    public function reportsCatalog(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $this->managerApp->assertManagerAccess($user, $gate);
        $this->managerApp->assertReportsAccess($user, $gate);

        return response()->json(
            $this->reportCatalog->catalogForUser($user, $gate),
        );
    }

    /** POST /manager/device-tokens — register FCM/APNs token for push (future server delivery). */
    public function registerDeviceToken(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $this->managerApp->assertManagerAccess($user, $gate);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);

        $record = $this->deviceTokens->register(
            $user,
            $data['token'],
            UserDeviceToken::CHANNEL_MANAGER,
            $data['platform'] ?? null,
        );

        return response()->json([
            'message' => 'Device token registered.',
            'id' => $record->id,
        ]);
    }

    /** DELETE /manager/device-tokens — unregister on sign-out. */
    public function unregisterDeviceToken(Request $request)
    {
        $user = $request->user();
        $gate = $this->erp->gateForUser($user);
        $this->managerApp->assertManagerAccess($user, $gate);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $this->deviceTokens->unregister($user, $data['token'], UserDeviceToken::CHANNEL_MANAGER);

        return response()->json(['message' => 'Device token removed.']);
    }
}
