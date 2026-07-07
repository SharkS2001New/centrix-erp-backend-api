<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\StockReceipt;
use App\Services\Audit\OperationalAuditService;
use App\Services\Notifications\AdminNotificationService;
use App\Services\Notifications\InAppNotificationEvents;
use Illuminate\Http\Request;

class StockReceiptController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return StockReceipt::class;
    }

    public function store(Request $request)
    {
        $response = parent::store($request);
        $receipt = $response->getData();
        $user = $request->user();

        if ($user && $receipt) {
            app(OperationalAuditService::class)->logStockMovement($user, 'receipt', [
                'receipt_id' => (int) ($receipt->id ?? 0),
                'product_code' => (string) ($receipt->product_code ?? ''),
                'branch_id' => (int) ($receipt->branch_id ?? 0),
                'units_received' => (float) ($receipt->units_received ?? 0),
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

        return $response;
    }
}
