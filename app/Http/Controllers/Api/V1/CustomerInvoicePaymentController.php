<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Http\Request;

class CustomerInvoicePaymentController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return CustomerInvoicePayment::class;
    }

    public function store(Request $request)
    {
        $response = parent::store($request);
        $payment = CustomerInvoicePayment::query()->find($response->getData(true)['id'] ?? null);

        if ($payment && $request->user()?->organization_id) {
            $organization = Organization::find($request->user()->organization_id);
            if ($organization) {
                app(CustomerNotificationService::class)->notifyInvoicePayment($payment, $organization);
            }
        }

        return $response;
    }
}
