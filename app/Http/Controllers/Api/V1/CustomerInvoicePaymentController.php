<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Services\Accounting\CustomerPaymentJournalService;
use App\Services\Erp\ErpContext;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Http\Request;

class CustomerInvoicePaymentController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return CustomerInvoicePayment::class;
    }

    public function store(Request $request)
    {
        $response = parent::store($request);
        $paymentId = $response->getData(true)['id'] ?? null;
        $payment = $paymentId
            ? CustomerInvoicePayment::query()->with('customerInvoice')->find($paymentId)
            : null;

        if ($payment && $request->user()?->organization_id) {
            $organization = Organization::find($request->user()->organization_id);
            if ($organization) {
                app(CustomerNotificationService::class)->notifyInvoicePayment($payment, $organization);
            }

            $sale = $payment->customerInvoice?->loadMissing('sale')?->sale;
            if ($sale && $payment->payment_method_id) {
                $gate = $this->erp->gateForUser($request->user());
                app(CustomerPaymentJournalService::class)->postIfEnabled(
                    $sale,
                    $request->user(),
                    $gate,
                    (float) $payment->amount_paid,
                    (int) $payment->payment_method_id,
                );
            }
        }

        return $response;
    }
}
