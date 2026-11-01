<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformLicenceCheckout;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformPlan;
use App\Models\WhatsappConfig;
use App\Services\MpesaService;
use App\Services\Platform\BillingPolicy;
use App\Services\Platform\FreePlanAccess;
use App\Services\Platform\OrganizationLicenseService;
use App\Services\Platform\PlatformPaybillLicenceService;
use App\Services\SystemIssues\SystemIssueAlertSettingsResolver;
use App\Services\Mpesa\MpesaC2bUrlRegistrationService;
use App\Services\Mpesa\MpesaSettingsResolver;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\ResolvedWhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PlatformPaybillController extends Controller
{
    public function __construct(protected PlatformPaybillLicenceService $paybill) {}

    protected function tokensTurnedOff(): ?\Illuminate\Http\JsonResponse
    {
        if (app(BillingPolicy::class)->tokensEnabled()) {
            return null;
        }

        return response()->json(['message' => 'Token billing is turned off.'], 422);
    }

    public function show()
    {
        $settings = $this->paybill->settings();

        return response()->json([
            'data' => $this->settingsPayload($settings),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'short_code' => 'nullable|string|max:20',
            'account_name_prefix' => 'nullable|string|max:40',
            'manual_mpesa_account_name' => 'nullable|string|max:160',
            'manual_mpesa_instructions' => 'nullable|string|max:1000',
            'equity_manual_paybill' => 'nullable|string|max:40',
            'equity_manual_account_name' => 'nullable|string|max:160',
            'equity_manual_account_number' => 'nullable|string|max:80',
            'equity_manual_instructions' => 'nullable|string|max:1000',
            'stk_env' => 'sometimes|in:sandbox,live',
            'stk_consumer_key' => 'nullable|string|max:255',
            'stk_consumer_secret' => 'nullable|string|max:500',
            'stk_shortcode' => 'nullable|string|max:20',
            'stk_passkey' => 'nullable|string|max:500',
            'stk_callback_url' => 'nullable|url|max:500',
            'c2b_confirmation_url' => 'nullable|url|max:500',
            'c2b_validation_url' => 'nullable|url|max:500',
            'clear_stk_credentials' => 'sometimes|boolean',
            'duration_discount_percent' => 'sometimes|numeric|min:0|max:100',
            'duration_discount_min_months' => 'sometimes|integer|min:0|max:36',
            'free_plan_access' => 'sometimes|array',
        ]);

        $settings = $this->paybill->saveSettings($data);
        if (array_key_exists('free_plan_access', $data) && is_array($data['free_plan_access'])) {
            app(FreePlanAccess::class)->persist($settings, $data['free_plan_access']);
            $settings = $settings->fresh() ?? $settings;
        }

        return response()->json([
            'data' => $this->settingsPayload($settings),
            'message' => 'Platform payment settings saved.',
        ]);
    }

    public function registerC2bUrls(MpesaC2bUrlRegistrationService $registration)
    {
        $settings = $this->paybill->settings();
        $config = MpesaSettingsResolver::normalize([
            'env' => $settings?->stk_env ?: 'sandbox',
            'consumer_key' => $settings?->stk_consumer_key,
            'consumer_secret' => $settings?->stk_consumer_secret,
            'child_storecode' => $settings?->short_code,
            'c2b_confirmation_url' => $settings?->c2b_confirmation_url,
            'c2b_validation_url' => $settings?->c2b_validation_url,
        ]);

        try {
            MpesaSettingsResolver::assertReadyForC2bRegistration($config);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['mpesa' => [$e->getMessage()]]);
        }

        try {
            return response()->json([
                'success' => true,
                'message' => 'Platform C2B URLs registered successfully with Daraja.',
                'registration' => $registration->register($config),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function payments(Request $request)
    {
        $query = PlatformLicencePayment::query()
            ->with('organization:id,company_code,org_name,primary_tel')
            ->orderByDesc('id');

        $status = trim((string) $request->query('status', ''));
        if (in_array($status, ['applied', 'unmatched'], true)) {
            $query->where('status', $status);
        }

        $product = trim((string) $request->query('product', ''));
        if (in_array($product, ['monthly', 'yearly', 'token'], true)) {
            $query->where('product', $product);
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date').' 23:59:59');
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('mpesa_transaction_id', 'like', $like)
                    ->orWhere('phone_number', 'like', $like)
                    ->orWhere('bill_ref_number', 'like', $like)
                    ->orWhere('account_reference', 'like', $like)
                    ->orWhereHas('organization', function ($org) use ($like) {
                        $org->where('org_name', 'like', $like)
                            ->orWhere('company_code', 'like', $like);
                    });
            });
        }

        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        return response()->json($query->paginate($perPage));
    }

    public function retry(PlatformLicencePayment $payment)
    {
        $payment = $this->paybill->retryUnapplied($payment->fresh());

        return response()->json([
            'message' => $payment->status === 'applied'
                ? 'Payment applied to the organisation licence.'
                : 'Payment is still unmatched. Check the account number and the plan price.',
            'data' => $payment->load('organization:id,company_code,org_name,primary_tel'),
        ]);
    }

    public function oneTimeQuote(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $result = $this->paybill->requestOneTimeQuote($org, $request->user());
        $message = $result['already']
            ? 'The Centrix administrator already has your quote request.'
            : ($result['emailed']
                ? 'Your quote request was emailed to the Centrix administrator.'
                : 'Your quote request was saved. The Centrix administrator could not be emailed just now.');

        return response()->json([
            'message' => $message,
            'data' => [
                'id' => $result['quote']->id,
                'status' => $result['quote']->status,
                'emailed' => $result['emailed'],
            ],
        ]);
    }

    public function checkout(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $renewal = $request->input('billing') === 'renew';
        $data = $request->validate([
            'plan_id' => [Rule::requiredIf(! $renewal), 'nullable', 'integer'],
            'months' => [Rule::requiredIf(! $renewal), 'nullable', 'integer', 'min:1', 'max:36'],
            'billing' => 'sometimes|in:monthly,yearly,renew',
        ]);
        if ($renewal) {
            $checkout = $this->paybill->startRenewalCheckout($org);
        } else {
            $billing = $data['billing'] ?? 'monthly';
            $plan = $this->findSelfServePlan((int) $data['plan_id']);
            $price = $billing === 'yearly'
                ? (float) ($plan?->yearly_price ?? 0)
                : (float) ($plan?->monthly_price ?? 0);
            if (! $plan || $price <= 0) {
                return response()->json(['message' => 'This plan is not available for the selected billing period.'], 422);
            }

            $checkout = $this->paybill->startCheckout(
                $org,
                $plan,
                $billing === 'yearly' ? 12 : (int) $data['months'],
                null,
                $billing,
            );
        }
        $instructions = $this->paybill->instructionsFor($org, null);
        if (! $instructions) {
            $checkout->delete();

            return response()->json(['message' => 'Manual M-Pesa payment instructions are not configured yet.'], 422);
        }

        return response()->json([
            'message' => 'Transfer the exact amount to the M-Pesa Paybill shown, then enter the transaction code.',
            'checkout' => $this->checkoutPayload($checkout),
            'paybill' => $instructions,
        ], 201);
    }

    public function equityCheckout(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $renewal = $request->input('billing') === 'renew';
        $data = $request->validate([
            'plan_id' => [Rule::requiredIf(! $renewal), 'nullable', 'integer'],
            'months' => [Rule::requiredIf(! $renewal), 'nullable', 'integer', 'min:1', 'max:36'],
            'billing' => 'sometimes|in:monthly,yearly,renew',
        ]);
        if ($renewal) {
            $checkout = $this->paybill->startRenewalCheckout($org);
        } else {
            $billing = $data['billing'] ?? 'monthly';
            $plan = $this->findSelfServePlan((int) $data['plan_id']);
            $price = $billing === 'yearly'
                ? (float) ($plan?->yearly_price ?? 0)
                : (float) ($plan?->monthly_price ?? 0);
            if (! $plan || $price <= 0) {
                return response()->json(['message' => 'This plan is not available for the selected billing period.'], 422);
            }

            $checkout = $this->paybill->startCheckout(
                $org,
                $plan,
                $billing === 'yearly' ? 12 : (int) $data['months'],
                null,
                $billing,
            );
        }
        $instructions = $this->paybill->equityInstructionsFor($org);
        if (! $instructions) {
            $checkout->delete();

            return response()->json(['message' => 'Equity payments are not configured yet.'], 422);
        }

        return response()->json([
            'checkout' => $this->checkoutPayload($checkout),
            'equity' => $instructions,
        ], 201);
    }

    public function verifyEquityCheckout(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $data = $request->validate([
            'checkout_id' => 'required|integer',
            'transaction_id' => 'required|string|max:80',
        ]);
        $payment = $this->paybill->acceptEquityCheckout(
            $org,
            (int) $data['checkout_id'],
            trim($data['transaction_id']),
        );
        $license = app(OrganizationLicenseService::class)->resolveForOrganization($org->fresh());

        return response()->json([
            'confirmed' => $payment->status === 'applied',
            'payment_status' => $payment->status,
            'message' => $payment->status === 'applied'
                ? 'Equity payment confirmed. Your plan is active.'
                : 'Equity payment has not been applied yet.',
            'license' => $license,
        ]);
    }

    public function notifyEquityCheckout(Request $request)
    {
        $user = $request->user();
        $org = $user?->organization;
        if (! $user || ! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $data = $request->validate([
            'checkout_id' => 'required|integer',
            'transaction_id' => 'required|string|max:100',
        ]);
        $transactionId = trim($data['transaction_id']);
        $checkout = PlatformLicenceCheckout::query()
            ->where('organization_id', $org->id)
            ->findOrFail((int) $data['checkout_id']);
        if ($checkout->status !== 'pending') {
            return response()->json(['message' => 'This licence payment is no longer pending review.'], 422);
        }

        $duplicate = PlatformLicenceCheckout::query()
            ->where('manual_payment_reference', $transactionId)
            ->whereKeyNot($checkout->id)
            ->exists();
        if ($duplicate) {
            return response()->json(['message' => 'This payment reference has already been submitted.'], 422);
        }

        if ($checkout->manual_payment_reference === $transactionId && $checkout->manual_payment_notified_at) {
            return response()->json([
                'message' => 'Your payment reference was already sent to the platform administrator for review.',
                'admin_notified' => true,
                'checkout' => $this->checkoutPayload($checkout),
            ]);
        }

        $checkout->manual_payment_reference = $transactionId;
        $checkout->manual_payment_notified_at = null;
        $checkout->save();

        $plan = $checkout->plan;
        $notified = app(\App\Services\Notifications\AdminNotificationService::class)->notifySuperAdmins($user, [
            'type' => 'licence_payment_review',
            'severity' => 'warning',
            'title' => 'Equity licence payment review',
            'message' => implode(' ', [
                $org->org_name.' ('.$org->company_code.') reported an Equity payment of',
                number_format((float) $checkout->amount, 2).' '.($plan?->currency ?: 'KES').'.',
                'Reference: '.$transactionId.'.',
                $plan ? 'Plan: '.$plan->name.'.' : '',
                'Verify the payment before activating the licence.',
            ]),
            'action_url' => '/platform/organizations/'.$org->id,
        ]);

        if ($notified === 0) {
            return response()->json([
                'message' => 'The payment reference was saved, but no active platform administrator could be notified. Please contact Centrix support.',
                'admin_notified' => false,
                'checkout' => $this->checkoutPayload($checkout),
            ], 503);
        }

        $checkout->manual_payment_notified_at = now();
        $checkout->save();

        return response()->json([
            'message' => 'Your payment reference was sent to the platform administrator for review. Your plan will activate after the payment is verified.',
            'admin_notified' => true,
            'checkout' => $this->checkoutPayload($checkout),
        ]);
    }

    public function notify(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $billing = $request->input('billing') === 'yearly' ? 'yearly' : ($request->input('billing') === 'renew' ? 'renew' : ($request->input('billing') === 'token' ? 'token' : 'monthly'));
        if ($billing === 'renew') {
            $checkout = $this->paybill->startRenewalCheckout($org);
            $instructions = $this->paybill->instructionsFor($org, null);
            $sent = $this->notifyAdmin($org, $checkout->plan_id ? PlatformPlan::query()->find($checkout->plan_id) : null, $checkout, $instructions);

            return response()->json([
                'message' => $sent
                    ? 'Centrix has been notified on WhatsApp. Pay the Paybill with your organisation code as the account number.'
                    : 'WhatsApp could not be sent. Pay the Paybill with your organisation code, then enter the M-Pesa code and check now.',
                'whatsapp_sent' => $sent,
                'checkout' => $this->checkoutPayload($checkout),
                'paybill' => $instructions,
            ]);
        }
        if ($billing === 'token') {
            if ($off = $this->tokensTurnedOff()) {
                return $off;
            }
            $checkout = $this->paybill->startTokenCheckout($org);
            if ((float) $checkout->amount <= 0) {
                $checkout->delete();

                return response()->json(['message' => 'A platform administrator has not published a token pack price yet.'], 422);
            }
            $instructions = $this->paybill->instructionsFor($org, null);
            $sent = $this->notifyAdmin($org, null, $checkout, $instructions);

            return response()->json([
                'message' => $sent
                    ? 'Centrix has been notified on WhatsApp. Pay the Paybill with your organisation code as the account number.'
                    : 'WhatsApp could not be sent. Pay the Paybill with your organisation code, then enter the M-Pesa code and check now.',
                'whatsapp_sent' => $sent,
                'checkout' => $this->checkoutPayload($checkout),
                'paybill' => $instructions,
            ]);
        }

        $plan = $this->activePublishedPlan($request, $billing);
        if ($plan instanceof \Illuminate\Http\JsonResponse) {
            return $plan;
        }

        $months = (int) $request->input('months');
        $checkout = $this->paybill->startCheckout($org, $plan, $billing === 'yearly' ? 12 : $months, null, $billing);
        $instructions = $this->paybill->instructionsFor($org, null);
        $sent = $this->notifyAdmin($org, $plan, $checkout, $instructions);

        return response()->json([
            'message' => $sent
                ? 'Centrix has been notified on WhatsApp. Pay the Paybill with your organisation code as the account number.'
                : 'WhatsApp could not be sent. Pay the Paybill with your organisation code, then enter the M-Pesa code and check now.',
            'whatsapp_sent' => $sent,
            'checkout' => $this->checkoutPayload($checkout),
            'paybill' => $instructions,
        ]);
    }

    public function stk(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        if (! $this->paybill->stkConfigured()) {
            return response()->json([
                'message' => 'M-Pesa STK is not configured. Choose manual M-Pesa or Equity transfer instead.',
            ], 422);
        }

        $needsPlan = ! in_array($request->input('billing'), ['token', 'renew'], true);
        $data = $request->validate([
            'plan_id' => [Rule::requiredIf($needsPlan), 'nullable', 'integer'],
            'months' => [Rule::requiredIf($needsPlan), 'nullable', 'integer', 'min:1', 'max:36'],
            'phone' => 'required|string|max:20',
            'billing' => 'sometimes|in:monthly,yearly,token,renew',
        ]);

        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            return response()->json(['message' => 'Enter a valid M-Pesa phone number.'], 422);
        }

        $billing = match ($data['billing'] ?? 'monthly') {
            'yearly' => 'yearly',
            'token' => 'token',
            'renew' => 'renew',
            default => 'monthly',
        };
        if ($billing === 'renew') {
            $checkout = $this->paybill->startRenewalCheckout($org, $phone);
        } elseif ($billing === 'token') {
            if ($off = $this->tokensTurnedOff()) {
                return $off;
            }
            $checkout = $this->paybill->startTokenCheckout($org, $phone);
            if ((float) $checkout->amount <= 0) {
                $checkout->delete();

                return response()->json(['message' => 'A platform administrator has not published a token pack price yet.'], 422);
            }
        } else {
            $plan = $this->findSelfServePlan((int) $data['plan_id']);
            $priced = $billing === 'yearly' ? (float) ($plan?->yearly_price ?? 0) : (float) ($plan?->monthly_price ?? 0);
            if (! $plan || $priced <= 0) {
                return response()->json(['message' => $billing === 'yearly' ? 'Choose an active yearly plan.' : 'Choose an active monthly plan.'], 422);
            }
            $checkout = $this->paybill->startCheckout(
                $org,
                $plan,
                $billing === 'yearly' ? 12 : (int) $data['months'],
                $phone,
                $billing,
            );
        }
        try {
            $stk = new MpesaService($this->paybill->stkConfig());
            $stk = $stk->stkPaybill(
                $phone,
                (int) round((float) $checkout->amount),
                (string) $org->company_code,
                $this->paybill->settings()?->stk_shortcode,
            );
        } catch (\Throwable $e) {
            Log::warning('Platform licence STK push failed.', [
                'organization_id' => $org->id,
                'checkout_id' => $checkout->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not send the M-Pesa prompt. Check the platform STK settings or choose manual M-Pesa.',
                'checkout' => $this->checkoutPayload($checkout),
                'stk' => false,
            ], 422);
        }

        $checkoutRequestId = trim((string) ($stk['CheckoutRequestID'] ?? ''));
        $responseCode = (string) ($stk['ResponseCode'] ?? '');
        if ($checkoutRequestId === '' || isset($stk['errorCode']) || ($responseCode !== '' && $responseCode !== '0')) {
            $darajaCode = trim((string) ($stk['errorCode'] ?? ($responseCode !== '' ? $responseCode : '')));
            $darajaMessage = trim((string) (
                $stk['errorMessage']
                ?? $stk['ResponseDescription']
                ?? $stk['CustomerMessage']
                ?? ''
            ));
            if ($darajaMessage === '') {
                $darajaMessage = $checkoutRequestId === ''
                    ? 'Daraja did not return a CheckoutRequestID.'
                    : 'Daraja rejected the STK request without providing an error description.';
            }
            $darajaMessage = mb_substr($darajaMessage, 0, 500);
            $message = 'Safaricom Daraja error'
                .($darajaCode !== '' ? ' '.$darajaCode : '')
                .': '.$darajaMessage;

            Log::warning('Platform licence STK prompt was rejected.', [
                'organization_id' => $org->id,
                'checkout_id' => $checkout->id,
                'error_code' => $darajaCode !== '' ? $darajaCode : null,
                'error_message' => $darajaMessage,
                'response_code' => $responseCode !== '' ? $responseCode : null,
                'response_description' => isset($stk['ResponseDescription'])
                    ? mb_substr((string) $stk['ResponseDescription'], 0, 500)
                    : null,
            ]);

            return response()->json([
                'message' => $message,
                'checkout' => $this->checkoutPayload($checkout),
                'stk' => false,
                'daraja' => [
                    'error_code' => $darajaCode !== '' ? $darajaCode : null,
                    'error_message' => $darajaMessage,
                    'response_code' => $responseCode !== '' ? $responseCode : null,
                    'response_description' => isset($stk['ResponseDescription'])
                        ? mb_substr((string) $stk['ResponseDescription'], 0, 500)
                        : null,
                    'request_id' => isset($stk['requestId']) ? mb_substr((string) $stk['requestId'], 0, 100) : null,
                ],
            ], 422);
        }

        $checkout->checkout_request_id = $checkoutRequestId;
        $checkout->save();

        return response()->json([
            'message' => 'M-Pesa prompt sent. Enter your PIN. This page confirms the subscription when Safaricom reports the payment.',
            'checkout' => $this->checkoutPayload($checkout->fresh()),
            'stk' => true,
        ]);
    }

    public function status(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $checkoutId = $request->query('checkout_id');
        $status = $this->paybill->statusFor($org, is_numeric($checkoutId) ? (int) $checkoutId : null);
        $license = $status['status'] === 'paid'
            ? app(OrganizationLicenseService::class)->resolveForOrganization($org->fresh())
            : null;

        return response()->json($status + [
            'license' => $license,
        ]);
    }

    public function verify(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $data = $request->validate([
            'mpesa_code' => 'nullable|string|max:20',
            'checkout_id' => 'nullable|integer',
            'plan_id' => 'nullable|integer',
            'months' => 'nullable|integer|min:1|max:36',
            'billing' => 'nullable|in:monthly,yearly,token,renew',
        ]);

        $billing = match ($data['billing'] ?? 'monthly') {
            'yearly' => 'yearly',
            'token' => 'token',
            'renew' => 'renew',
            default => 'monthly',
        };
        $checkout = null;
        if (! empty($data['checkout_id'])) {
            $checkout = PlatformLicenceCheckout::query()
                ->whereKey((int) $data['checkout_id'])
                ->where('organization_id', $org->id)
                ->first();
            if (! $checkout) {
                return response()->json(['message' => 'This payment request does not belong to your organisation.'], 404);
            }
        } elseif ($billing === 'renew') {
            $this->paybill->startRenewalCheckout($org);
        } elseif ($billing === 'token') {
            if ($off = $this->tokensTurnedOff()) {
                return $off;
            }
            $this->paybill->startTokenCheckout($org);
        } elseif (! empty($data['plan_id'])) {
            $plan = PlatformPlan::query()->where('is_active', true)->find($data['plan_id']);
            $priced = $billing === 'yearly' ? (float) ($plan?->yearly_price ?? 0) : (float) ($plan?->monthly_price ?? 0);
            if ($plan && $priced > 0) {
                $this->paybill->startCheckout(
                    $org,
                    $plan,
                    $billing === 'yearly' ? 12 : (int) ($data['months'] ?? 1),
                    null,
                    $billing,
                );
            }
        }

        $code = strtoupper(preg_replace('/\s+/', '', trim((string) ($data['mpesa_code'] ?? ''))) ?? '');
        $payment = null;
        $foreign = false;
        if ($code !== '') {
            $payment = PlatformLicencePayment::query()
                ->where('mpesa_transaction_id', $code)
                ->first();
            if ($payment && $payment->organization_id && (int) $payment->organization_id !== (int) $org->id) {
                $foreign = true;
                $payment = null;
            } elseif ($checkout && $checkout->status === 'paid') {
                $payment = $checkout->mpesa_receipt === $code ? $payment : null;
            } elseif ($checkout && $checkout->status === 'pending'
                && $payment && abs((float) $payment->amount - (float) $checkout->amount) > 1) {
                return response()->json(['message' => 'The M-Pesa amount does not match this checkout.'], 422);
            } elseif ($payment && ! $payment->organization_id
                && (($checkout && $checkout->status === 'pending')
                    || $this->pendingCheckoutMatches($org, (float) $payment->amount))) {
                $payment->organization_id = $org->id;
                $payment->bill_ref_number = $payment->bill_ref_number ?: $org->company_code;
                $payment->save();
                $payment = $this->paybill->retryUnapplied($payment->fresh(), $checkout?->id);
            } elseif ($payment && (int) $payment->organization_id === (int) $org->id && $payment->status !== 'applied') {
                $payment = $this->paybill->retryUnapplied($payment, $checkout?->id);
            }
        }

        $license = app(OrganizationLicenseService::class)->resolveForOrganization($org->fresh());
        $checkoutPaid = $checkout?->status === 'paid' && $checkout?->mpesa_receipt === $code;

        return response()->json([
            'confirmed' => $checkoutPaid || ($payment && $payment->status === 'applied'),
            'payment_status' => $payment?->status,
            'message' => $code === ''
                ? 'Enter the M-Pesa code from the Paybill payment.'
                : ($foreign
                    ? 'That M-Pesa code belongs to a different organisation.'
                    : ($checkoutPaid
                        ? 'M-Pesa code confirmed. This organisation is paid.'
                        : ($payment
                        ? ($payment->status === 'applied'
                            ? 'M-Pesa code confirmed. This organisation is paid.'
                            : 'That M-Pesa code is recorded but does not match a plan amount yet.')
                        : 'That M-Pesa code is not in Centrix yet. Wait a moment and check again.'))),
            'license' => $license,
        ]);
    }

    protected function activePublishedPlan(Request $request, string $billing): PlatformPlan|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'plan_id' => 'required|integer',
            'months' => 'required|integer|min:1|max:36',
        ]);
        $plan = $this->findSelfServePlan((int) $data['plan_id']);
        $priced = $billing === 'yearly' ? (float) ($plan->yearly_price ?? 0) : (float) ($plan->monthly_price ?? 0);
        if (! $plan || $priced <= 0) {
            return response()->json(['message' => $billing === 'yearly' ? 'Choose an active yearly plan.' : 'Choose an active monthly plan.'], 422);
        }

        return $plan;
    }

    protected function findSelfServePlan(int $planId): ?PlatformPlan
    {
        $query = PlatformPlan::query()->where('is_active', true)->whereKey($planId);
        if (Schema::hasColumn('platform_plans', 'self_serve')) {
            $query->where('self_serve', true);
        }

        return $query->first();
    }

    protected function pendingCheckoutMatches(Organization $org, float $amount): bool
    {
        if (! Schema::hasTable('platform_licence_checkouts')) {
            return false;
        }

        return PlatformLicenceCheckout::query()
            ->where('organization_id', $org->id)
            ->where('status', 'pending')
            ->whereBetween('amount', [$amount - 1, $amount + 1])
            ->exists();
    }

    protected function platformOrganization(): ?Organization
    {
        $platformCode = (string) config('erp.platform_company_code', 'PLATFORM');

        return Organization::query()
            ->whereRaw('UPPER(company_code) = ?', [strtoupper($platformCode)])
            ->first();
    }

    /** @param  array<string, mixed>|null  $instructions */
    protected function notifyAdmin(Organization $org, ?PlatformPlan $plan, PlatformLicenceCheckout $checkout, ?array $instructions): bool
    {
        $to = SystemIssueAlertSettingsResolver::whatsappNumberE164();
        $config = $this->whatsappConfig();
        if (! $to || ! $config) {
            return false;
        }

        $paybill = $instructions['short_code'] ?? $this->paybill->settings()?->short_code ?? '';
        $message = implode("\n", [
            'Centrix licence payment',
            $org->org_name.' ('.$org->company_code.')',
            $plan
                ? 'Plan: '.$plan->name.' for '.$checkout->months.' '.($plan->interval === 'annual' ? 'year' : 'month(s)')
                : 'Token pack',
            'Amount: '.number_format((float) $checkout->amount, 2).' '.($plan?->currency ?: 'KES'),
            'Paybill: '.$paybill,
            'Account: '.$org->company_code,
            'They paid the Paybill and asked you to confirm it.',
        ]);

        return app(MetaWhatsAppClient::class)->sendText($config, $to, $message);
    }

    protected function whatsappConfig(): ?ResolvedWhatsAppConfig
    {
        $row = WhatsappConfig::query()
            ->where('is_active', true)
            ->whereNotNull('phone_number_id')
            ->where('phone_number_id', '!=', '')
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->first();

        if ($row) {
            return ResolvedWhatsAppConfig::fromModel($row);
        }

        $token = trim((string) config('whatsapp.access_token', ''));
        $phoneNumberId = trim((string) config('whatsapp.phone_number_id', ''));
        if ($token === '' || $phoneNumberId === '') {
            return null;
        }

        $org = WhatsAppSettingsResolver::platformOrganization();

        return new ResolvedWhatsAppConfig(
            organizationId: (int) ($org?->id ?? 0),
            branchId: null,
            botUserId: (int) config('whatsapp.bot_user_id', 0),
            phoneNumberId: $phoneNumberId,
            accessToken: $token,
            webhookVerifyToken: WhatsAppSettingsResolver::platformVerifyToken(),
            graphApiVersion: (string) config('whatsapp.graph_api_version', 'v21.0'),
        );
    }

    /** @return array<string, mixed> */
    protected function settingsPayload(?\App\Models\PlatformBillingSetting $settings): array
    {
        return [
            'short_code' => $settings?->short_code ?? '',
            'account_name_prefix' => $settings?->account_name_prefix ?? '',
            'manual_mpesa_account_name' => $settings?->manual_mpesa_account_name ?? '',
            'manual_mpesa_instructions' => $settings?->manual_mpesa_instructions ?? '',
            'stk_env' => $settings?->stk_env ?: 'sandbox',
            'stk_consumer_key' => $settings?->stk_consumer_key ? '********' : '',
            'stk_shortcode' => $settings?->stk_shortcode ?? '',
            'stk_callback_url' => $settings?->stk_callback_url ?? '',
            'c2b_confirmation_url' => $settings?->c2b_confirmation_url ?? '',
            'c2b_validation_url' => $settings?->c2b_validation_url ?? '',
            'has_stk_consumer_key' => (bool) $settings?->stk_consumer_key,
            'has_stk_consumer_secret' => (bool) $settings?->stk_consumer_secret,
            'has_stk_passkey' => (bool) $settings?->stk_passkey,
            'equity_manual_paybill' => $settings?->equity_manual_paybill ?? '',
            'equity_manual_account_name' => $settings?->equity_manual_account_name ?? '',
            'equity_manual_account_number' => $settings?->equity_manual_account_number ?? '',
            'equity_manual_instructions' => $settings?->equity_manual_instructions ?? '',
            'organization_id' => $settings?->organization_id,
            'duration_discount_percent' => (float) ($settings?->duration_discount_percent ?? 0),
            'duration_discount_min_months' => (int) ($settings?->duration_discount_min_months ?? 3),
            'free_plan_access' => app(FreePlanAccess::class)->stored(),
            'free_plan_catalog' => app(FreePlanAccess::class)->catalog(),
            'payment_methods' => [
                'mpesa_stk' => $this->paybill->stkConfigured(),
                'mpesa_manual' => trim((string) ($settings?->short_code ?? '')) !== '',
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function checkoutPayload(PlatformLicenceCheckout $checkout): array
    {
        return [
            'id' => $checkout->id,
            'amount' => (float) $checkout->amount,
            'months' => (int) $checkout->months,
            'discount_percent' => (float) $checkout->discount_percent,
            'status' => $checkout->status,
            'manual_payment_reference' => $checkout->manual_payment_reference,
            'manual_payment_notified_at' => $checkout->manual_payment_notified_at?->toIso8601String(),
        ];
    }
}
