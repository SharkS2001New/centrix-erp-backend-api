<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\StockReceipt;
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
