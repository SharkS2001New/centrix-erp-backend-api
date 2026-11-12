<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockReceiveRequest;
use App\Services\Accounting\PurchaseReceiveJournalService;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\StockReceiveService;
use Illuminate\Http\Request;

class LpoReceiveController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected StockReceiveService $receives,
    ) {}

    public function store(StockReceiveRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        $orgId = (int) ($user?->organization_id ?? 0);
        $procurement = \App\Services\Purchasing\ProcurementSettingsResolver::forOrganizationId($orgId);

        if (empty($data['stock_location'])) {
            $data['stock_location'] = $procurement['default_receive_location'] ?? 'shop';
        }

        // Shop-only / small_shop: POS sells from shop — never park receive qty in store.
        $organization = $user?->organization_id
            ? \App\Models\Organization::query()->find($user->organization_id)
            : null;
        if (
            $organization
            && (
                ($organization->deployment_profile ?? '') === 'small_shop'
                || \App\Services\Purchasing\ProcurementSettingsResolver::isShopOnlySales($organization)
            )
        ) {
            $data['stock_location'] = 'shop';
        }

        $receipt = $this->receives->receive($data, $user);

        $gate = $this->erp->gateForUser($user);
        app(PurchaseReceiveJournalService::class)->postIfEnabled($receipt, $user, $gate);

        return response()->json($receipt, 201);
    }
}
