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
        if (empty($data['stock_location'])) {
            $orgId = (int) ($request->user()?->organization_id ?? 0);
            $procurement = \App\Services\Purchasing\ProcurementSettingsResolver::forOrganizationId($orgId);
            $data['stock_location'] = $procurement['default_receive_location'] ?? 'store';
        }

        $receipt = $this->receives->receive($data, $request->user());

        $gate = $this->erp->gateForUser($request->user());
        app(PurchaseReceiveJournalService::class)->postIfEnabled($receipt, $request->user(), $gate);

        return response()->json($receipt, 201);
    }
}
