<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PlatformInvoice;
use App\Models\PlatformLicenceBalance;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Platform\BillingPolicy;
use App\Services\Platform\OrganizationLicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class OrganizationBillingController extends Controller
{
    public function show(Request $request, OrganizationLicenseService $licenses, BillingPolicy $policy)
    {
        $org = $request->user()?->organization;
        if (! $org) {
            return response()->json(['message' => 'No organisation on this login.'], 422);
        }

        $license = $licenses->resolveForOrganization($org);
        $subscription = PlatformSubscription::query()->with('plan')->where('organization_id', $org->id)->first();
        $plan = $subscription?->plan;

        return response()->json([
            'data' => [
                'license' => $license,
                'policy' => $policy->current(),
                'subscription' => $subscription ? [
                    'id' => $subscription->id,
                    'status' => $subscription->status,
                    'is_trial' => (bool) $subscription->is_trial,
                    'current_period_start' => $subscription->current_period_start?->toIso8601String(),
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                    'amount' => $subscription->amount !== null ? (float) $subscription->amount : null,
                    'renewal_price' => $subscription->renewal_price !== null ? (float) $subscription->renewal_price : null,
                    'currency' => $subscription->currency ?: 'KES',
                    'interval' => $plan?->interval,
                    'plan_name' => $plan?->name,
                    'yearly_price' => $plan?->yearly_price !== null ? (float) $plan->yearly_price : null,
                    'monthly_price' => $plan?->monthly_price !== null ? (float) $plan->monthly_price : null,
                    'seat_limit' => $plan?->seat_limit,
                    'modules' => $license['modules'] ?? [],
                ] : null,
                'plans' => $this->catalogue(),
                'usage' => [
                    'users' => User::query()->where('organization_id', $org->id)->count(),
                    'branches' => Schema::hasTable('branches')
                        ? Branch::query()->where('organization_id', $org->id)->count()
                        : 0,
                ],
                'invoices' => PlatformInvoice::query()
                    ->where('organization_id', $org->id)
                    ->orderByDesc('id')
                    ->limit(8)
                    ->get(['id', 'invoice_number', 'status', 'issue_date', 'total', 'currency'])
                    ->map(fn (PlatformInvoice $invoice) => [
                        'id' => $invoice->id,
                        'number' => $invoice->invoice_number,
                        'status' => $invoice->status,
                        'date' => $invoice->issue_date?->toDateString(),
                        'total' => (float) $invoice->total,
                        'currency' => $invoice->currency ?: 'KES',
                    ])
                    ->all(),
                'payments' => PlatformLicencePayment::query()
                    ->where('organization_id', $org->id)
                    ->orderByDesc('id')
                    ->limit(8)
                    ->get(['id', 'created_at', 'amount', 'status', 'product', 'mpesa_transaction_id', 'note'])
                    ->map(fn (PlatformLicencePayment $payment) => [
                        'id' => $payment->id,
                        'at' => $payment->created_at?->toDateTimeString(),
                        'amount' => (float) $payment->amount,
                        'status' => $payment->status,
                        'product' => $payment->product,
                        'reference' => $payment->mpesa_transaction_id,
                        'note' => $payment->note,
                    ])
                    ->all(),
            ],
        ]);
    }

    public function policy(BillingPolicy $policy)
    {
        return response()->json(['data' => $policy->current()]);
    }

    public function updatePolicy(Request $request, BillingPolicy $policy)
    {
        $data = $request->validate([
            'token_value_kes' => 'sometimes|integer|min:1|max:100000',
            'token_pack_tokens' => 'sometimes|integer|min:1|max:1000000',
            'token_pack_price_kes' => 'sometimes|integer|min:0|max:100000000',
            'expiry_policy' => 'sometimes|in:admin,never,90_days',
            'low_balance_first_percent' => 'sometimes|integer|min:0|max:100',
            'low_balance_second_percent' => 'sometimes|integer|min:0|max:100',
            'grace_enabled' => 'sometimes|boolean',
            'grace_max_tokens' => 'sometimes|integer|min:0|max:100',
            'sale_tokens' => 'sometimes|integer|min:0|max:1000',
            'write_costs' => 'sometimes|array',
            'write_costs.create' => 'sometimes|integer|min:0|max:1000',
            'write_costs.update' => 'sometimes|integer|min:0|max:1000',
            'write_costs.delete' => 'sometimes|integer|min:0|max:1000',
            'offline' => 'sometimes|array',
            'offline.enabled' => 'sometimes|boolean',
            'offline.max_allowance' => 'sometimes|integer|min:0|max:100000',
            'offline.grant_hours' => 'sometimes|integer|min:1|max:720',
            'offline.sync_hours' => 'sometimes|integer|min:1|max:720',
        ]);

        return response()->json([
            'data' => $policy->save($data),
            'message' => 'Billing policy saved.',
        ]);
    }

    public function overview()
    {
        $plans = PlatformPlan::query()->orderBy('name')->get();
        $subscriptions = PlatformSubscription::query()->get(['id', 'status', 'is_trial', 'plan_id', 'amount']);
        $active = $subscriptions->filter(fn ($row) => in_array($row->status, ['active', 'trialing'], true))->count();

        return response()->json([
            'data' => [
                'active_subscriptions' => $active,
                'plans' => $plans->count(),
                'token_organisations' => Schema::hasTable('platform_licence_balances')
                    ? PlatformLicenceBalance::query()->where('token_balance', '>', 0)->count()
                    : 0,
                'unmatched_payments' => PlatformLicencePayment::query()->where('status', 'unmatched')->count(),
                'catalogue' => $plans->map(fn (PlatformPlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'interval' => $plan->interval,
                    'monthly_price' => $plan->monthly_price !== null ? (float) $plan->monthly_price : null,
                    'yearly_price' => $plan->yearly_price !== null ? (float) $plan->yearly_price : null,
                    'token_pack_price' => $plan->token_pack_price !== null ? (float) $plan->token_pack_price : null,
                    'self_serve' => (bool) ($plan->self_serve ?? false),
                    'is_active' => (bool) $plan->is_active,
                    'organisations' => $subscriptions->where('plan_id', $plan->id)->count(),
                ])->values(),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    protected function catalogue(): array
    {
        if (! Schema::hasTable('platform_plans')) {
            return [];
        }

        $query = PlatformPlan::query()->where('is_active', true)->orderBy('monthly_price')->orderBy('name');
        if (Schema::hasColumn('platform_plans', 'self_serve')) {
            $query->where('self_serve', true);
        }

        return $query->get()->map(fn (PlatformPlan $plan) => [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'interval' => $plan->interval,
            'currency' => $plan->currency ?: 'KES',
            'monthly_price' => $plan->monthly_price !== null ? (float) $plan->monthly_price : null,
            'yearly_price' => $plan->yearly_price !== null ? (float) $plan->yearly_price : null,
            'features' => collect(is_array($plan->feature_details) ? $plan->feature_details : [])
                ->map(fn ($row) => is_array($row) ? trim((string) ($row['label'] ?? '')) : '')
                ->filter()
                ->values()
                ->all(),
            'modules' => array_values($plan->workspace_keys ?? []),
        ])->all();
    }
}
