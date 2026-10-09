<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PlatformLicenceCheckout;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformPlan;
use App\Services\MpesaService;
use App\Services\Platform\OrganizationLicenseService;
use App\Services\Platform\PlatformPaybillLicenceService;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

class PlatformPaybillController extends Controller
{
    public function __construct(protected PlatformPaybillLicenceService $paybill) {}

    public function show()
    {
        $settings = $this->paybill->settings();

        return response()->json([
            'data' => [
                'short_code' => $settings?->short_code ?? '',
                'account_name_prefix' => $settings?->account_name_prefix ?? '',
                'duration_discount_percent' => (float) ($settings?->duration_discount_percent ?? 0),
                'duration_discount_min_months' => (int) ($settings?->duration_discount_min_months ?? 3),
                'organization_id' => $settings?->organization_id,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'short_code' => 'nullable|string|max:20',
            'account_name_prefix' => 'nullable|string|max:40',
            'duration_discount_percent' => 'sometimes|numeric|min:0|max:100',
            'duration_discount_min_months' => 'sometimes|integer|min:1|max:36',
        ]);

        $settings = $this->paybill->saveSettings($data);

        return response()->json([
            'data' => [
                'short_code' => $settings->short_code ?? '',
                'account_name_prefix' => $settings->account_name_prefix ?? '',
                'duration_discount_percent' => (float) ($settings->duration_discount_percent ?? 0),
                'duration_discount_min_months' => (int) ($settings->duration_discount_min_months ?? 3),
                'organization_id' => $settings->organization_id,
            ],
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

        $data = $request->validate([
            'plan_id' => 'required|integer',
            'months' => 'required|integer|min:1|max:36',
        ]);
        $plan = PlatformPlan::query()->where('is_active', true)->find($data['plan_id']);
        if (! $plan || (float) ($plan->monthly_price ?? 0) <= 0) {
            return response()->json(['message' => 'Choose an active monthly plan.'], 422);
        }

        $checkout = $this->paybill->startCheckout($org, $plan, (int) $data['months']);

        return response()->json([
            'message' => 'Pay this exact amount on the Centrix Paybill, then enter the M-Pesa code.',
            'checkout' => $this->checkoutPayload($checkout),
        ]);
    }

    public function stk(Request $request)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $data = $request->validate([
            'plan_id' => 'required|integer',
            'months' => 'required|integer|min:1|max:36',
            'phone' => 'required|string|max:20',
        ]);

        $plan = PlatformPlan::query()->where('is_active', true)->find($data['plan_id']);
        if (! $plan || (float) ($plan->monthly_price ?? 0) <= 0) {
            return response()->json(['message' => 'Choose an active monthly plan.'], 422);
        }

        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            return response()->json(['message' => 'Enter a valid M-Pesa phone number.'], 422);
        }

        $checkout = $this->paybill->startCheckout($org, $plan, (int) $data['months'], $phone);
        $platform = $this->platformOrganization();
        if (! $platform) {
            return response()->json([
                'message' => 'M-Pesa STK is not set up for Centrix yet. Pay the Paybill, then enter the M-Pesa code.',
                'checkout' => $this->checkoutPayload($checkout),
                'stk' => false,
            ], 200);
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
                'message' => 'Could not send the M-Pesa prompt. Pay the Paybill instead, then enter the M-Pesa code. '.$e->getMessage(),
                'checkout' => $this->checkoutPayload($checkout),
                'stk' => false,
            ], 200);
        }

        $checkout->checkout_request_id = $stk['CheckoutRequestID'] ?? null;
        $checkout->save();

        return response()->json([
            'message' => 'M-Pesa prompt sent. Enter your PIN on the phone, then check the payment.',
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
        ]);

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
            } elseif ($payment && (int) $payment->organization_id === (int) $org->id && $payment->status !== 'applied') {
                $payment = $this->paybill->retryUnapplied($payment);
            }
        }

        $license = app(OrganizationLicenseService::class)->resolveForOrganization($org->fresh());
        $confirmed = ($payment && $payment->status === 'applied')
            || in_array($license['access'] ?? '', ['full', 'token'], true);

        return response()->json([
            'confirmed' => $confirmed && ($code === '' || ($payment && $payment->status === 'applied')),
            'payment_status' => $payment?->status,
            'message' => $code === ''
                ? 'Checked the licence on this organisation.'
                : ($foreign
                    ? 'That M-Pesa code belongs to a different organisation.'
                    : ($payment
                        ? ($payment->status === 'applied'
                            ? 'M-Pesa code confirmed. This organisation is paid.'
                            : 'That M-Pesa code is recorded but not applied to a plan yet.')
                        : 'That M-Pesa code is not in Centrix yet. Wait a moment and check again.')),
            'license' => $license,
        ]);
    }

    protected function platformOrganization(): ?Organization
    {
        $platformCode = (string) config('erp.platform_company_code', 'PLATFORM');

        return Organization::query()
            ->whereRaw('UPPER(company_code) = ?', [strtoupper($platformCode)])
            ->first();
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
