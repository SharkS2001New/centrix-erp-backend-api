<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Services\Platform\FreePlanAccess;
use App\Services\Platform\PlatformPaybillLicenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Public catalogue of active self-serve plans for the Centrix marketing site.
 * Prices and packages are whatever platform admins publish under Self-serve plans.
 */
class PublicSelfServePlansController extends Controller
{
    public function index(Request $request)
    {
        $freePlanName = app(FreePlanAccess::class)->name();

        if (! Schema::hasTable('platform_plans')) {
            return response()->json([
                'data' => [],
                'industries' => ['commerce', 'hospitality', 'spa', 'driving'],
                'starting' => null,
                'free_plan_name' => $freePlanName,
            ]);
        }

        $industry = trim((string) $request->query('industry', ''));
        $allowed = ['commerce', 'hospitality', 'spa', 'driving'];
        if ($industry !== '' && ! in_array($industry, $allowed, true)) {
            return response()->json(['message' => 'Unknown industry.'], 422);
        }

        $query = PlatformPlan::query()
            ->where('is_active', true);

        if (Schema::hasColumn('platform_plans', 'self_serve')) {
            $query->where('self_serve', true);
        }

        $query->where(function ($inner) {
            $inner->where('monthly_price', '>', 0)->orWhere('yearly_price', '>', 0);
        });

        if ($industry !== '' && Schema::hasColumn('platform_plans', 'industry')) {
            $query->where(function ($inner) use ($industry) {
                $inner->where('industry', $industry);
                if ($industry === 'commerce') {
                    $inner->orWhereNull('industry')->orWhere('industry', '');
                }
            });
        }

        if (Schema::hasColumn('platform_plans', 'sort_order')) {
            $query->orderBy('sort_order');
        }
        $query->orderBy('monthly_price')->orderBy('name');

        $discount = app(PlatformPaybillLicenceService::class)->durationDiscount();
        $plans = $query->get()
            ->map(fn (PlatformPlan $plan) => $this->serialize($plan, $discount))
            ->values()
            ->all();

        $starting = null;
        foreach ($plans as $plan) {
            $price = $plan['monthly_price'];
            if ($price === null || $price <= 0) {
                continue;
            }
            if ($starting === null || $price < $starting['monthly_price']) {
                $starting = $plan;
            }
        }

        return response()->json([
            'data' => $plans,
            'industries' => $allowed,
            'starting' => $starting,
            'currency' => 'KES',
            'source' => 'platform_plans',
            'free_plan_name' => $freePlanName,
            /** Same duration discount platform admins set under Paybill — used for 12-month annual quotes. */
            'duration_discount' => [
                'percent' => $discount['percent'],
                'min_months' => $discount['min_months'],
            ],
        ]);
    }

    /**
     * @param  array{percent: float, min_months: int}  $discount
     * @return array<string, mixed>
     */
    protected function serialize(PlatformPlan $plan, array $discount): array
    {
        $highlights = [];
        foreach (is_array($plan->feature_details) ? $plan->feature_details : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $detail = trim((string) ($row['detail'] ?? ''));
            $highlights[] = $detail !== '' ? $label.': '.$detail : $label;
        }

        if ($highlights === [] && is_array($plan->feature_keys)) {
            foreach ($plan->feature_keys as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $highlights[] = $key;
                }
            }
        }

        $industry = trim((string) ($plan->industry ?: 'commerce'));
        if ($industry === '') {
            $industry = 'commerce';
        }

        $interval = in_array(strtolower((string) $plan->interval), ['annual', 'yearly', 'year'], true)
            ? 'yearly'
            : 'monthly';

        $monthly = $plan->monthly_price !== null ? round((float) $plan->monthly_price, 2) : null;
        $yearly = $plan->yearly_price !== null ? round((float) $plan->yearly_price, 2) : null;

        // Annual toggle on marketing site: 12 × monthly minus duration discount (same as ERP prepaid).
        $annualQuote = null;
        if ($monthly !== null && $monthly > 0) {
            $annualQuote = PlatformPaybillLicenceService::quoteMonths(
                $monthly,
                12,
                (int) $discount['min_months'],
                (float) $discount['percent'],
            );
        }

        return [
            'id' => (int) $plan->id,
            'name' => (string) $plan->name,
            'description' => (string) ($plan->description ?? ''),
            'currency' => (string) ($plan->currency ?: 'KES'),
            'interval' => $interval,
            'monthly_price' => $monthly,
            'yearly_price' => $yearly,
            'annual_price' => $annualQuote !== null ? $annualQuote['amount'] : $yearly,
            'annual_gross' => $annualQuote !== null ? $annualQuote['gross'] : $yearly,
            'annual_discount' => $annualQuote !== null ? $annualQuote['discount'] : 0,
            'industry' => $industry,
            'trial_profile_keys' => is_array($plan->trial_profile_keys) ? array_values($plan->trial_profile_keys) : [],
            'recommended' => Schema::hasColumn('platform_plans', 'is_recommended') && (bool) $plan->is_recommended,
            'cta_action' => in_array($plan->cta_action, ['buy_now', 'start_now', 'view_details', 'contact_sales'], true)
                ? $plan->cta_action
                : 'buy_now',
            'cta_label' => $plan->callToActionLabel(),
            'highlights' => array_values($highlights),
            'trial_days' => Schema::hasColumn('platform_plans', 'trial_days') && $plan->trial_days !== null
                ? (int) $plan->trial_days
                : null,
            'discount_percent' => (float) $discount['percent'],
            'discount_min_months' => (int) $discount['min_months'],
        ];
    }
}
