<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Sale;
use App\Models\User;
use App\Services\Audit\OperationalAuditService;
use App\Services\Fulfillment\AutoTripAssignmentService;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Non-critical checkout side effects (notifications, audit, trip assign).
 * Stock deduction / AR / journals stay on FinalizeSaleAfterCheckoutJob (afterResponse).
 */
class CheckoutSideEffectsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public int $saleId,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $sale = Sale::query()->with(['items', 'payments'])->find($this->saleId);
        $user = User::query()->find($this->userId);
        if (! $sale || ! $user) {
            return;
        }

        try {
            $organization = Organization::find($user->organization_id);
            if ($organization) {
                app(CustomerNotificationService::class)->notifyOrderPlaced($sale, $organization);
            }
        } catch (Throwable $e) {
            Log::warning('Queued order-placed notification failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            app(OperationalAuditService::class)->logSaleCheckout($user, $sale);
        } catch (Throwable $e) {
            Log::warning('Queued checkout audit failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if ($sale->status !== 'pending_approval') {
                app(AutoTripAssignmentService::class)->tryAssignSale($sale, $user);
            }
        } catch (Throwable $e) {
            Log::warning('Queued auto-trip assignment failed', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
