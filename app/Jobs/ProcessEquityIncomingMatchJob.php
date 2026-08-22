<?php

namespace App\Jobs;

use App\Models\EquityIncomingPayment;
use App\Models\Organization;
use App\Services\Equity\EquityPaymentApplicationService;
use App\Services\Equity\EquityPaymentMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessEquityIncomingMatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $paymentId) {}

    public function handle(
        EquityPaymentMatchingService $matching,
        EquityPaymentApplicationService $application,
    ): void {
        $payment = EquityIncomingPayment::query()->find($this->paymentId);
        if (! $payment || $payment->status !== 'available') {
            return;
        }

        $organization = Organization::query()->find((int) $payment->organization_id);
        if (! $organization || ! $matching->isEnabledForOrganization($organization)) {
            return;
        }

        try {
            $payment = $matching->enrichPayment($payment);
            $best = $matching->findBestMatch($payment);
            if (! $best || ! $matching->shouldAutoApply($organization, $best)) {
                return;
            }

            $user = $matching->resolveActingUser($payment, $best['sale']);
            if (! $user) {
                return;
            }

            $application->applyToSale(
                $payment,
                $best['sale'],
                $user,
                null,
                (string) $best['method'],
                'Auto-applied from Equity callback',
            );
        } catch (Throwable) {
            // Leave unmatched for manual reconciliation.
        }
    }
}
