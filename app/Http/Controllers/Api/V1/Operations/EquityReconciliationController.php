<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\EquityIncomingPayment;
use App\Models\Organization;
use App\Models\Sale;
use App\Services\Auth\UserAccessService;
use App\Services\Equity\EquityPaymentApplicationService;
use App\Services\Equity\EquityPaymentMatchingService;
use App\Services\Equity\EquitySettingsResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EquityReconciliationController extends Controller
{
    public function __construct(
        protected EquityPaymentMatchingService $matchingService,
        protected EquityPaymentApplicationService $applicationService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $organizationId = (int) (app(UserAccessService::class)->organizationId($user, $request) ?? $user->organization_id ?? 0);
        if ($organizationId <= 0) {
            abort(403, 'Your account is not linked to an organization.');
        }

        $organization = Organization::findOrFail($organizationId);
        if (! EquitySettingsResolver::isPaybillReconciliationEnabledForOrganization($organization)) {
            return response()->json($this->disabledPayload($organization));
        }

        $payments = EquityIncomingPayment::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'available')
            ->where('reconciliation_status', '!=', 'ignored')
            ->orderByDesc('received_at')
            ->limit(200)
            ->get();

        $matched = EquityIncomingPayment::query()
            ->with('sale:id,order_num')
            ->where('organization_id', $organizationId)
            ->where('reconciliation_status', 'matched')
            ->whereNotNull('applied_sale_id')
            ->orderByDesc('matched_at')
            ->limit(100)
            ->get();

        return response()->json([
            'enabled' => true,
            'payments' => $payments->map(fn (EquityIncomingPayment $payment) => $this->presentPayment($payment)),
            'matched_payments' => $matched->map(fn (EquityIncomingPayment $payment) => $this->presentPayment($payment)),
            'summary' => [
                'count' => $payments->count(),
                'total_amount' => (int) $payments->sum('amount'),
                'matched_count' => $matched->count(),
            ],
            'settings' => [
                'payment_account_hint' => EquitySettingsResolver::paymentAccountHintForOrganization($organization),
            ],
        ]);
    }

    public function show(Request $request, int $paymentId)
    {
        $user = $request->user();
        $organizationId = (int) (app(UserAccessService::class)->organizationId($user, $request) ?? $user->organization_id ?? 0);
        $this->assertReconciliationEnabled($organizationId);

        $payment = $this->findPayment($paymentId, $organizationId);
        $candidates = $this->matchingService->findCandidates($payment);

        return response()->json([
            'payment' => $this->presentPayment($payment),
            'candidates' => $candidates,
        ]);
    }

    public function apply(Request $request, int $paymentId)
    {
        $user = $request->user();
        $organizationId = (int) (app(UserAccessService::class)->organizationId($user, $request) ?? $user->organization_id ?? 0);
        $this->assertReconciliationEnabled($organizationId);

        $data = $request->validate([
            'sale_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->findPayment($paymentId, $organizationId);
        $sale = Sale::query()
            ->where('id', $data['sale_id'])
            ->where('organization_id', $organizationId)
            ->first();

        if (! $sale) {
            throw ValidationException::withMessages([
                'sale_id' => ['Order was not found for your organization.'],
            ]);
        }

        $applied = $this->applicationService->applyToSale(
            $payment,
            $sale,
            $user,
            isset($data['amount']) ? (float) $data['amount'] : null,
            'manual',
            $data['notes'] ?? null,
        );

        return response()->json([
            'payment' => $this->presentPayment($applied),
            'sale' => [
                'id' => (int) $sale->id,
                'order_num' => (int) $sale->fresh()->order_num,
                'amount_paid' => (float) $sale->fresh()->amount_paid,
                'payment_status' => (string) $sale->fresh()->payment_status,
            ],
        ]);
    }

    public function ignore(Request $request, int $paymentId)
    {
        $user = $request->user();
        $organizationId = (int) (app(UserAccessService::class)->organizationId($user, $request) ?? $user->organization_id ?? 0);
        $this->assertReconciliationEnabled($organizationId);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->findPayment($paymentId, $organizationId);
        $payment->update([
            'reconciliation_status' => 'ignored',
            'reconciliation_notes' => $data['notes'] ?? null,
            'matched_by_user_id' => $user->id,
            'matched_at' => now(),
        ]);

        return response()->json([
            'payment' => $this->presentPayment($payment->fresh()),
        ]);
    }

    protected function assertReconciliationEnabled(int $organizationId): void
    {
        if ($organizationId <= 0) {
            abort(403, 'Your account is not linked to an organization.');
        }

        $organization = Organization::find($organizationId);
        if (! $organization || ! EquitySettingsResolver::isPaybillReconciliationEnabledForOrganization($organization)) {
            abort(409, 'Equity paybill reconciliation is not enabled for this organization.');
        }
    }

    /** @return array<string, mixed> */
    protected function disabledPayload(Organization $organization): array
    {
        return [
            'enabled' => false,
            'payments' => [],
            'matched_payments' => [],
            'summary' => [
                'count' => 0,
                'total_amount' => 0,
                'matched_count' => 0,
            ],
            'settings' => [
                'payment_account_hint' => EquitySettingsResolver::paymentAccountHintForOrganization($organization),
            ],
            'message' => 'Equity paybill reconciliation is turned off. Enable it under Admin → Settings → Finance → Equity Bank.',
        ];
    }

    protected function findPayment(int $paymentId, int $organizationId): EquityIncomingPayment
    {
        $payment = EquityIncomingPayment::query()
            ->where('id', $paymentId)
            ->where('organization_id', $organizationId)
            ->first();

        if (! $payment) {
            abort(404, 'Payment not found.');
        }

        return $payment;
    }

    protected function presentPayment(EquityIncomingPayment $payment): array
    {
        $orderNum = $payment->parsed_order_num ? (int) $payment->parsed_order_num : null;
        if (! $orderNum && $payment->relationLoaded('sale') && $payment->sale) {
            $orderNum = $payment->sale->order_num ? (int) $payment->sale->order_num : null;
        } elseif (! $orderNum && $payment->applied_sale_id) {
            $orderNum = Sale::query()->where('id', $payment->applied_sale_id)->value('order_num');
            $orderNum = $orderNum ? (int) $orderNum : null;
        }

        return [
            'id' => (int) $payment->id,
            'transaction_id' => $payment->transaction_id,
            'phone_number' => $payment->phone_number,
            'amount' => (int) $payment->amount,
            'bill_ref_number' => $payment->bill_ref_number,
            'payer_name' => $payment->payer_name,
            'business_account_number' => $payment->business_account_number,
            'equity_bank_account_id' => $payment->equity_bank_account_id
                ? (int) $payment->equity_bank_account_id
                : null,
            'parsed_order_num' => $payment->parsed_order_num ? (int) $payment->parsed_order_num : null,
            'order_num' => $orderNum,
            'parsed_customer_num' => $payment->parsed_customer_num ? (int) $payment->parsed_customer_num : null,
            'source' => $payment->source,
            'status' => $payment->status,
            'reconciliation_status' => $payment->reconciliation_status,
            'match_method' => $payment->match_method,
            'match_confidence' => $payment->match_confidence,
            'applied_sale_id' => $payment->applied_sale_id ? (int) $payment->applied_sale_id : null,
            'applied_invoice_id' => $payment->applied_invoice_id ? (int) $payment->applied_invoice_id : null,
            'applied_amount' => $payment->applied_amount ? (int) $payment->applied_amount : null,
            'received_at' => $payment->received_at?->toIso8601String(),
            'matched_at' => $payment->matched_at?->toIso8601String(),
            'reconciliation_notes' => $payment->reconciliation_notes,
        ];
    }
}
