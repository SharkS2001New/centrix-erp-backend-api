<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformLicenceCheckout;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformPlan;
use App\Models\WhatsappConfig;
use App\Services\MpesaService;
use App\Services\Platform\OrganizationLicenseService;
use App\Services\Platform\PlatformPaybillLicenceService;
use App\Services\SystemIssues\SystemIssueAlertSettingsResolver;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\ResolvedWhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PlatformPaybillController extends Controller
{
    public function __construct(protected PlatformPaybillLicenceService $paybill) {}

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
            'duration_discount_percent' => 'sometimes|numeric|min:0|max:100',
            'duration_discount_min_months' => 'sometimes|integer|min:0|max:36',
        ]);

        $settings = $this->paybill->saveSettings($data);

        return response()->json([
            'data' => $this->settingsPayload($settings),
            'message' => 'Centrix Paybill saved.',
        ]);
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

        return response()->json([
            'data' => $query->limit(100)->get(),
        ]);
    }

    public function checkout(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $plan = $this->activeMonthlyPlan($request);
        if ($plan instanceof \Illuminate\Http\JsonResponse) {
            return $plan;
        }

        $checkout = $this->paybill->startCheckout($org, $plan, (int) $request->input('months'));
        $instructions = $this->paybill->instructionsFor($org, null);

        return response()->json([
            'message' => 'Pay the Paybill with the account number shown, then notify Centrix on WhatsApp or enter the M-Pesa code.',
            'checkout' => $this->checkoutPayload($checkout),
            'paybill' => $instructions,
        ]);
    }

    public function notify(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $billing = $request->input('billing') === 'yearly' ? 'yearly' : ($request->input('billing') === 'token' ? 'token' : 'monthly');
        if ($billing === 'token') {
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

        $data = $request->validate([
            'plan_id' => 'required_unless:billing,token|integer',
            'months' => 'required_unless:billing,token|integer|min:1|max:36',
            'phone' => 'required|string|max:20',
            'billing' => 'sometimes|in:monthly,yearly,token',
        ]);

        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            return response()->json(['message' => 'Enter a valid M-Pesa phone number.'], 422);
        }

        $billing = ($data['billing'] ?? 'monthly') === 'yearly' ? 'yearly' : (($data['billing'] ?? 'monthly') === 'token' ? 'token' : 'monthly');
        if ($billing === 'token') {
            $checkout = $this->paybill->startTokenCheckout($org, $phone);
            if ((float) $checkout->amount <= 0) {
                $checkout->delete();

                return response()->json(['message' => 'A platform administrator has not published a token pack price yet.'], 422);
            }
        } else {
            $plan = $this->findSelfServePlan((int) $data['plan_id']);
            $priced = $billing === 'yearly' ? (float) ($plan->yearly_price ?? 0) : (float) ($plan->monthly_price ?? 0);
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
        $platform = $this->platformOrganization();
        if (! $platform) {
            return response()->json([
                'message' => 'M-Pesa prompt is not set up yet. Pay the Paybill with your organisation code, then notify Centrix or enter the M-Pesa code.',
                'checkout' => $this->checkoutPayload($checkout),
                'stk' => false,
            ]);
        }

        try {
            $stk = MpesaService::forOrganization($platform)->stkPaybill(
                $phone,
                (int) round((float) $checkout->amount),
                (string) $org->company_code,
                $this->paybill->settings()?->short_code,
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not send the M-Pesa prompt. Pay the Paybill with your organisation code, then enter the M-Pesa code. '.$e->getMessage(),
                'checkout' => $this->checkoutPayload($checkout),
                'stk' => false,
            ]);
        }

        $checkout->checkout_request_id = $stk['CheckoutRequestID'] ?? null;
        $checkout->save();

        return response()->json([
            'message' => 'M-Pesa prompt sent. Enter your PIN, then check the payment with the M-Pesa code.',
            'checkout' => $this->checkoutPayload($checkout->fresh()),
            'stk' => true,
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
            'plan_id' => 'nullable|integer',
            'months' => 'nullable|integer|min:1|max:36',
            'billing' => 'nullable|in:monthly,yearly,token',
        ]);

        $billing = ($data['billing'] ?? 'monthly') === 'yearly' ? 'yearly' : (($data['billing'] ?? '') === 'token' ? 'token' : 'monthly');
        if ($billing === 'token') {
            $this->paybill->startTokenCheckout($org);
        } elseif (! empty($data['plan_id'])) {
            $plan = PlatformPlan::query()->where('is_active', true)->find($data['plan_id']);
            $priced = $billing === 'yearly' ? (float) ($plan->yearly_price ?? 0) : (float) ($plan->monthly_price ?? 0);
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
            } elseif ($payment && ! $payment->organization_id && $this->pendingCheckoutMatches($org, (float) $payment->amount)) {
                $payment->organization_id = $org->id;
                $payment->bill_ref_number = $payment->bill_ref_number ?: $org->company_code;
                $payment->save();
                $payment = $this->paybill->retryUnapplied($payment->fresh());
            } elseif ($payment && (int) $payment->organization_id === (int) $org->id && $payment->status !== 'applied') {
                $payment = $this->paybill->retryUnapplied($payment);
            }
        }

        $license = app(OrganizationLicenseService::class)->resolveForOrganization($org->fresh());

        return response()->json([
            'confirmed' => $payment && $payment->status === 'applied',
            'payment_status' => $payment?->status,
            'message' => $code === ''
                ? 'Enter the M-Pesa code from the Paybill payment.'
                : ($foreign
                    ? 'That M-Pesa code belongs to a different organisation.'
                    : ($payment
                        ? ($payment->status === 'applied'
                            ? 'M-Pesa code confirmed. This organisation is paid.'
                            : 'That M-Pesa code is recorded but does not match a plan amount yet.')
                        : 'That M-Pesa code is not in Centrix yet. Wait a moment and check again.')),
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
            'organization_id' => $settings?->organization_id,
            'duration_discount_percent' => (float) ($settings?->duration_discount_percent ?? 0),
            'duration_discount_min_months' => (int) ($settings?->duration_discount_min_months ?? 3),
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
        ];
    }
}
