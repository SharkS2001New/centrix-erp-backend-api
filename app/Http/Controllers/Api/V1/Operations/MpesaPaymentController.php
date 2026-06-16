<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartAccess;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesCartPayments;
use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesMpesaPayments;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MpesaIncomingPayment;
use App\Models\MpesaPaymentSkip;
use App\Models\MpesaStkRequest;
use App\Models\Organization;
use App\Models\TemporaryCart;
use App\Services\Erp\ErpContext;
use App\Services\Mpesa\MpesaSettingsResolver;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class MpesaPaymentController extends Controller
{
    use HandlesCartAccess;
    use HandlesCartPayments;
    use HandlesMpesaPayments;

    public function __construct(protected ErpContext $erp) {}

    protected function mpesaForCart(TemporaryCart $cart, $user): MpesaService
    {
        $org = Organization::findOrFail($user->organization_id);
        $branch = $cart->branch_id ? Branch::find($cart->branch_id) : null;

        return MpesaService::forOrganization($org, $branch);
    }

    public function stkPush(Request $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $org = Organization::findOrFail($request->user()->organization_id);
        $mpesaConfig = MpesaSettingsResolver::forOrganization($org);
        if (! MpesaSettingsResolver::isStkPushEnabled($mpesaConfig)) {
            return response()->json([
                'message' => 'STK push is disabled for this organization. Enable it under Admin → Settings → Finance.',
            ], 422);
        }

        $mpesaService = $this->mpesaForCart($cart, $request->user());
        $data = $request->validate([
            'phone_number' => ['required', 'string', 'max:45'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $phone = $this->formatMpesaPhone($data['phone_number']);
        $amountDue = $this->cartAmountDue($cart);
        $amount = isset($data['amount']) && $data['amount'] !== null
            ? min((int) ceil((float) $data['amount']), (int) ceil($amountDue))
            : (int) ceil($amountDue);

        if ($amount < 1) {
            throw new InvalidArgumentException('Nothing to pay on this cart.');
        }

        try {
            $stkResponse = $mpesaService->stkPush(
                $phone,
                $amount,
                'POS-CART-' . $cart->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (isset($stkResponse['errorCode'])) {
            $message = (string) ($stkResponse['errorMessage'] ?? 'M-Pesa request failed.');

            return response()->json([
                'message' => $message,
                'error' => [
                    'requestId' => $stkResponse['requestId'] ?? '',
                    'errorCode' => $stkResponse['errorCode'],
                    'errorMessage' => $message,
                ],
            ], 422);
        }

        if (isset($stkResponse['ResponseCode']) && (string) $stkResponse['ResponseCode'] !== '0') {
            $message = (string) ($stkResponse['ResponseDescription'] ?? 'M-Pesa rejected the STK push request.');

            return response()->json([
                'message' => $message,
                'error' => [
                    'errorCode' => (string) $stkResponse['ResponseCode'],
                    'errorMessage' => $message,
                ],
            ], 422);
        }

        $displayPhone = $this->displayMpesaPhone($phone);
        $stkRequest = MpesaStkRequest::create([
            'cart_id' => $cart->id,
            'organization_id' => (int) $request->user()->organization_id,
            'phone_number' => $displayPhone,
            'amount' => $amount,
            'merchant_request_id' => $stkResponse['MerchantRequestID'] ?? null,
            'checkout_request_id' => $stkResponse['CheckoutRequestID'] ?? null,
            'status' => 'pending',
        ]);

        $cart->update(['mpesa_phone' => $displayPhone]);
        $cart->increment('update_no');

        return response()->json([
            'success' => [
                'MerchantRequestID' => $stkResponse['MerchantRequestID'] ?? null,
                'CheckoutRequestID' => $stkResponse['CheckoutRequestID'] ?? null,
                'ResponseCode' => $stkResponse['ResponseCode'] ?? null,
                'ResponseDescription' => $stkResponse['ResponseDescription'] ?? null,
                'CustomerMessage' => $stkResponse['CustomerMessage'] ?? 'STK push sent. Check your phone.',
            ],
            'stk_request_id' => $stkRequest->id,
            'cart' => $cart->fresh('lines'),
            'amount' => $amount,
            'amount_due' => $this->cartAmountDue($cart->fresh('lines')),
        ]);
    }

    public function paymentStatus(Request $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $phone = (string) ($request->query('phone') ?: $cart->mpesa_phone ?: '');
        $stkRequest = MpesaStkRequest::where('cart_id', $cart->id)
            ->where('organization_id', (int) $request->user()->organization_id)
            ->latest('id')
            ->first();

        $stkError = null;
        if ($stkRequest?->status === 'failed') {
            $stkError = $this->mpesaFailureMessage($stkRequest->result_code, $stkRequest->result_desc);
        }

        $candidates = $phone !== ''
            ? $this->incomingPaymentsForCart($cart, $phone, (int) $request->user()->organization_id)->map(fn ($p) => $this->formatIncomingPayment($p))
            : collect();

        return response()->json([
            'status' => $stkRequest?->status ?? 'none',
            'stk_error' => $stkRequest?->status === 'completed' ? null : $stkError,
            'stk_amount' => $stkRequest?->amount,
            'stk_paid_amount' => $stkRequest?->paid_amount,
            'result_code' => $stkRequest?->result_code,
            'result_desc' => $stkRequest?->result_desc,
            'transaction_id' => $stkRequest?->transaction_id ?? $cart->mpesa_transaction_code,
            'paid_amount' => (float) ($cart->mpesa_payment_amount ?? 0),
            'amount_due' => $this->cartAmountDue($cart),
            'candidates' => $candidates,
            'cart' => $cart->fresh('lines'),
        ]);
    }

    public function lookupIncomingPayments(Request $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $data = $request->validate([
            'phone_number' => ['required', 'string', 'max:45'],
        ]);

        $this->formatMpesaPhone($data['phone_number']);
        $candidates = $this->incomingPaymentsForCart($cart, $data['phone_number'], (int) $request->user()->organization_id)
            ->map(fn ($p) => $this->formatIncomingPayment($p));

        return response()->json([
            'candidates' => $candidates,
            'amount_due' => $this->cartAmountDue($cart),
            'cart' => $cart->fresh('lines'),
        ]);
    }

    public function applyIncomingPayment(Request $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'payment_id' => ['required', 'integer'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $payment = MpesaIncomingPayment::query()
            ->where('id', $data['payment_id'])
            ->where('status', 'available')
            ->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))
            ->first();

        if (! $payment) {
            throw new InvalidArgumentException('Payment not found or already used.');
        }

        $amountDue = $this->cartAmountDue($cart);
        if ($amountDue <= 0) {
            throw new InvalidArgumentException('This order is already fully paid.');
        }

        $paymentAmount = (float) $payment->amount;
        $requested = isset($data['amount']) && $data['amount'] !== null
            ? (float) $data['amount']
            : $paymentAmount;
        $toApply = min($requested, $paymentAmount, $amountDue);
        if ($toApply < 1) {
            throw new InvalidArgumentException('Payment amount is too small to apply.');
        }

        $remainder = (int) round($paymentAmount - $toApply);
        if ($remainder >= 1) {
            MpesaIncomingPayment::query()->create([
                'organization_id' => $payment->organization_id ?? $orgId,
                'transaction_id' => $payment->transaction_id.'-R'.$remainder.'-'.uniqid(),
                'phone_number' => $payment->phone_number,
                'amount' => $remainder,
                'source' => $payment->source,
                'status' => 'available',
                'stk_request_id' => $payment->stk_request_id,
                'received_at' => $payment->received_at,
            ]);
        }

        $payment->update([
            'status' => 'applied',
            'applied_cart_id' => $cart->id,
            'applied_amount' => (int) round($toApply),
            'applied_at' => now(),
            'organization_id' => $payment->organization_id ?? $orgId,
        ]);

        $cart = $this->refreshCartMpesaTotals($cart);

        return response()->json([
            'applied_amount' => (int) round($toApply),
            'payment' => $this->formatIncomingPayment($payment->fresh()),
            'amount_due' => $this->cartAmountDue($cart),
            'cart' => $cart,
        ]);
    }

    public function skipIncomingPayment(Request $request, int $cartId)
    {
        $cart = $this->findOwnedCart($cartId, $request->user());
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'payment_id' => ['required', 'integer'],
        ]);

        $payment = MpesaIncomingPayment::query()
            ->where('id', $data['payment_id'])
            ->where('status', 'available')
            ->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))
            ->firstOrFail();

        MpesaPaymentSkip::firstOrCreate([
            'cart_id' => $cart->id,
            'mpesa_incoming_payment_id' => $payment->id,
        ]);

        return response()->json([
            'ok' => true,
            'candidates' => $this->incomingPaymentsForCart(
                $cart,
                (string) ($cart->mpesa_phone ?: $payment->phone_number),
                $orgId,
            )->map(fn ($p) => $this->formatIncomingPayment($p)),
        ]);
    }

    public function stkCallback(Request $request)
    {
        $payload = json_decode($request->getContent());
        $callback = $payload->Body->stkCallback ?? null;

        if (! $callback) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback payload']);
        }

        $checkoutRequestId = $callback->CheckoutRequestID ?? null;
        $resultCode = (int) ($callback->ResultCode ?? 1);
        $resultDesc = (string) ($callback->ResultDesc ?? 'Transaction failed');

        $stkRequest = $checkoutRequestId
            ? MpesaStkRequest::where('checkout_request_id', $checkoutRequestId)->first()
            : null;

        if (! $stkRequest) {
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        if ($resultCode !== 0) {
            $stkRequest->update([
                'status' => 'failed',
                'result_code' => $resultCode,
                'result_desc' => $this->mpesaFailureMessage($resultCode, $resultDesc),
                'completed_at' => now(),
            ]);

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        $metadata = $callback->CallbackMetadata->Item ?? [];
        $values = [];
        foreach ($metadata as $item) {
            if (isset($item->Name)) {
                $values[$item->Name] = $item->Value ?? null;
            }
        }

        $transactionId = (string) ($values['MpesaReceiptNumber'] ?? '');
        $paidAmount = (int) ($values['Amount'] ?? $stkRequest->amount);
        $phoneNumber = (string) ($values['PhoneNumber'] ?? $stkRequest->phone_number);

        if ($transactionId === '') {
            $stkRequest->update([
                'status' => 'failed',
                'result_code' => $resultCode,
                'result_desc' => 'M-Pesa payment succeeded but no receipt number was returned.',
                'completed_at' => now(),
            ]);

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        $stkRequest->update([
            'status' => 'completed',
            'transaction_id' => $transactionId,
            'paid_amount' => $paidAmount,
            'result_code' => $resultCode,
            'result_desc' => $resultDesc,
            'completed_at' => now(),
        ]);

        $this->recordIncomingMpesaPayment(
            $transactionId,
            $phoneNumber,
            $paidAmount,
            'stk',
            $stkRequest->organization_id,
            $stkRequest->id,
        );

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function c2bConfirmation(Request $request)
    {
        $payload = $this->parseMpesaCallbackPayload($request);
        Log::info('M-Pesa C2B confirmation received', $payload);

        $transactionId = (string) ($payload['TransID'] ?? $payload['trans_id'] ?? '');
        $amount = (int) round((float) ($payload['TransAmount'] ?? $payload['trans_amount'] ?? 0));
        $phone = (string) ($payload['MSISDN'] ?? $payload['msisdn'] ?? $payload['PhoneNumber'] ?? '');

        if ($transactionId === '' || $amount < 1 || $phone === '') {
            Log::warning('M-Pesa C2B confirmation ignored — missing fields', [
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'phone' => $phone,
            ]);

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted',
            ]);
        }

        $payment = $this->recordIncomingMpesaPayment(
            $transactionId,
            $phone,
            $amount,
            'c2b',
            MpesaSettingsResolver::organizationIdForC2bPayload($payload),
        );

        Log::info('M-Pesa C2B payment stored for check payment', [
            'payment_id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'phone_number' => $payment->phone_number,
            'amount' => $payment->amount,
            'organization_id' => $payment->organization_id,
        ]);

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Success',
        ]);
    }

    public function validationRequest(Request $request)
    {
        Log::info('M-Pesa C2B validation received', $this->parseMpesaCallbackPayload($request));

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Success']);
    }

    /** @return array<string, mixed> */
    protected function parseMpesaCallbackPayload(Request $request): array
    {
        $json = json_decode($request->getContent(), true);
        if (is_array($json) && $json !== []) {
            return $json;
        }

        return $request->all();
    }

    protected function formatIncomingPayment(MpesaIncomingPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'transaction_id' => $payment->transaction_id,
            'phone_number' => $payment->phone_number,
            'amount' => (int) $payment->amount,
            'source' => $payment->source,
            'received_at' => $payment->received_at?->toIso8601String(),
            'status' => $payment->status,
        ];
    }
}
