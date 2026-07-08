<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\StockReceiveRequest;
use App\Services\Accounting\PurchaseReceiveJournalService;
use App\Services\Audit\OperationalAuditService;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\StockReceiveService;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;

class StockReceiptController extends BaseResourceController
{
    public function __construct(
        protected StockReceiveService $receives,
        protected ErpContext $erp,
    ) {}

    protected function modelClass(): string
    {
        return \App\Models\StockReceipt::class;
    }

    public function store(Request $request)
    {
        $receiveRequest = StockReceiveRequest::createFrom($request);
        $receiveRequest->setContainer(app())->setRedirector(app('redirect'));
        $receiveRequest->validateResolved();

        $data = $receiveRequest->validated();
        if (empty($data['stock_location'])) {
            $orgId = (int) ($request->user()?->organization_id ?? 0);
            $procurement = \App\Services\Purchasing\ProcurementSettingsResolver::forOrganizationId($orgId);
            $data['stock_location'] = $procurement['default_receive_location'] ?? 'store';
        }

        $receipt = $this->receives->receive($data, $request->user());

        $gate = $this->erp->gateForUser($request->user());
        app(PurchaseReceiveJournalService::class)->postIfEnabled($receipt, $request->user(), $gate);

        $user = $request->user();
        if ($user) {
            app(OperationalAuditService::class)->logStockMovement($user, 'receipt', [
                'receipt_id' => (int) $receipt->id,
                'product_code' => (string) $receipt->product_code,
                'branch_id' => (int) $receipt->branch_id,
                'units_received' => (float) $receipt->units_received,
                'stock_location' => (string) ($receipt->stock_location ?? 'store'),
            ]);

            app(AdminNotificationService::class)->notifyPermission($user, 'inventory.manage', [
                'type' => 'info',
                'severity' => 'default',
                'title' => 'Stock receipt recorded',
                'message' => ($user->full_name ?: $user->username)
                    ." recorded receipt of {$receipt->units_received} {$receipt->product_code}.",
                'action_url' => '/inventory/receipts',
            ], InAppNotificationEvents::STOCK_RECEIPT);
        }

        return response()->json($receipt, 201);
    }
}
