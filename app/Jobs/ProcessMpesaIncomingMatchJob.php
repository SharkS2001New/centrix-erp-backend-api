<?php

namespace App\Jobs;

use App\Models\MpesaIncomingPayment;
use App\Models\Organization;
use App\Services\Mpesa\MpesaPaymentApplicationService;
use App\Services\Mpesa\MpesaPaymentMatchingService;
use App\Services\Mpesa\MpesaSettingsResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMpesaIncomingMatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $paymentId) {}

    public function handle(
        MpesaPaymentMatchingService $matchingService,
        MpesaPaymentApplicationService $applicationService,
    ): void {
        $payment = MpesaIncomingPayment::query()->find($this->paymentId);
        if (! $payment || $payment->status !== 'available') {
            return;
        }

        $organization = Organization::query()->find((int) $payment->organization_id);
        if (! $organization || ! $matchingService->isEnabledForOrganization($organization)) {
            return;
        }

        $payment = $matchingService->enrichPayment($payment);
        $best = $matchingService->findBestMatch($payment);
        if (! $best) {
            return;
        }

        if (! $matchingService->shouldAutoApply($organization, $best)) {
            return;
        }

        $user = $matchingService->resolveActingUser($payment, $best['sale']);
        if (! $user) {
            Log::warning('M-Pesa auto-match skipped — no acting user', [
                'payment_id' => $payment->id,
                'sale_id' => $best['sale']->id,
            ]);

            return;
        }

        try {
            $applicationService->applyToSale(
                $payment,
                $best['sale'],
                $user,
                null,
                (string) $best['method'],
                'Auto-matched from C2B reference',
            );
        } catch (\Throwable $e) {
            Log::warning('M-Pesa auto-match failed', [
                'payment_id' => $payment->id,
                'sale_id' => $best['sale']->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
