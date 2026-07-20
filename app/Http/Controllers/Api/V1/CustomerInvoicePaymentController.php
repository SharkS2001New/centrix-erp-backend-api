<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoice;
use App\Models\CustomerInvoicePayment;
use App\Models\Organization;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\CustomerPaymentJournalService;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\ErpContext;
use App\Services\Fulfillment\TripAutoCloseService;
use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerInvoicePaymentController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return CustomerInvoicePayment::class;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $data = $request->validate([
            'customer_invoice_id' => 'required|integer|exists:customer_invoices,id',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'amount_paid' => 'required|numeric|min:0.01',
            'customer_num' => 'nullable|integer',
            'reference_number' => 'nullable|string|max:100',
            'cheque_number' => 'nullable|string|max:45',
            'date_paid' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $organizationId = (int) (app(UserAccessService::class)->organizationId($user, $request) ?? $user->organization_id ?? 0);
        if ($organizationId <= 0) {
            abort(403, 'Your account is not linked to an organization.');
        }

        $invoice = CustomerInvoice::query()->find($data['customer_invoice_id']);
        if (! $invoice || (int) $invoice->organization_id !== $organizationId) {
            throw ValidationException::withMessages([
                'customer_invoice_id' => ['Invoice was not found for your organization.'],
            ]);
        }

        $invoiceService = app(CustomerInvoiceService::class);
        $balance = $invoiceService->balanceDueFromPayments($invoice);
        if ($balance <= 0) {
            throw ValidationException::withMessages([
                'amount_paid' => ['This invoice has already been fully paid.'],
            ]);
        }

        $amountPaid = round((float) $data['amount_paid'], 2);
        if ($amountPaid - $balance > 0.01) {
            throw ValidationException::withMessages([
                'amount_paid' => [sprintf('Payment cannot exceed the invoice balance of %.2f.', $balance)],
            ]);
        }

        $payment = CustomerInvoicePayment::create([
            'customer_invoice_id' => $invoice->id,
            'customer_num' => $data['customer_num'] ?? $invoice->customer_num,
            'payment_method_id' => $data['payment_method_id'],
            'amount_paid' => $amountPaid,
            'amount_due_snapshot' => $balance,
            'reference_number' => $data['reference_number'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'date_paid' => $data['date_paid'] ?? now()->toDateString(),
            'received_by' => $user->id,
            'organization_id' => $organizationId,
            'branch_id' => $invoice->branch_id ? (int) $invoice->branch_id : ($user->branch_id ? (int) $user->branch_id : null),
            'notes' => $data['notes'] ?? null,
        ]);

        if ($this->auditable()) {
            $this->auditLogger()->logModel($user, 'create', $payment, request: $request);
        }

        $invoice = $invoiceService->finalizeRecordedPayment($payment, $user);
        $payment->setRelation('customerInvoice', $invoice);

        $organization = Organization::find($organizationId);
        if ($organization) {
            app(CustomerNotificationService::class)->notifyInvoicePayment($payment, $organization);
        }

        $sale = $invoice->loadMissing('sale')?->sale;
        if ($sale) {
            $gate = $this->erp->gateForUser($user);
            app(CustomerPaymentJournalService::class)->postIfEnabled(
                $sale,
                $user,
                $gate,
                (float) $payment->amount_paid,
                (int) $payment->payment_method_id,
            );
        }

        return response()->json($payment, 201);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $payment = $this->findScopedModel($request, $id);

        if ($this->auditable()) {
            $this->auditLogger()->logModel(
                $user,
                'delete',
                $payment,
                $payment->getAttributes(),
                null,
                $request,
            );
        }

        app(CustomerInvoiceService::class)->voidInvoicePayment($payment, $user);

        return response()->json(null, 204);
    }
}
