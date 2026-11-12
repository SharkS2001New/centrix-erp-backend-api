<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\FreePlanAccess;
use App\Services\Platform\PublicFreeSignupService;
use Illuminate\Http\Request;

/**
 * Instant trial signup from the Centrix marketing site.
 */
class PublicFreeSignupController extends Controller
{
    public function options(PublicFreeSignupService $signups, FreePlanAccess $freePlan)
    {
        if (! $signups->enabled()) {
            return response()->json([
                'enabled' => false,
                'message' => 'Public free signup is temporarily unavailable.',
            ], 503);
        }

        $discount = app(\App\Services\Platform\PlatformPaybillLicenceService::class)->durationDiscount();

        return response()->json([
            'enabled' => true,
            'free_plan_name' => $freePlan->name(),
            'trial_days' => $signups->trialDays(),
            'duration_discount' => [
                'percent' => (float) ($discount['percent'] ?? 0),
                'min_months' => (int) ($discount['min_months'] ?? 3),
            ],
            'trial_support' => $signups->trialSupport(),
            'pricing_note' => 'Your Instant trial is free. After the trial, pricing is recalculated from your setup type and the number of users you need — monthly, or yearly with the platform discount.',
            'industries' => $signups->industryOptions(),
            'setup_types' => $signups->setupTypeOptions(),
            'business_types' => $signups->businessTypeOptions(),
            'login_url' => rtrim((string) config('erp.frontend_url'), '/').'/login',
        ]);
    }

    public function store(Request $request, PublicFreeSignupService $signups)
    {
        $data = $request->validate([
            'org_name' => 'required|string|max:200',
            'org_email' => 'required|email|max:200',
            'primary_tel' => 'required|string|max:45',
            'org_address' => 'nullable|string|max:400',
            'industry' => 'required_without:setup_type|string|max:40',
            'setup_type' => 'required_with:plan_id|nullable|string|max:60',
            'plan_id' => 'nullable|integer|min:1',
            'business_type' => 'required_with:setup_type|string|max:100',
            'admin_full_name' => 'required|string|max:200',
            'admin_password' => 'required|string|min:8|max:100',
        ]);

        $result = $signups->signup($data);

        return response()->json([
            'message' => 'Your CentrixERP Instant trial is ready. Sign in with the company code and username below.',
            'company_code' => $result['company_code'],
            'username' => $result['username'],
            'org_name' => $result['organization']->org_name,
            'industry' => $result['industry'],
            'business_type' => $result['business_type'],
            'setup_type' => $result['setup_type'],
            'setup_type_label' => $result['setup_type_label'],
            'free_plan_name' => $result['free_plan_name'],
            'trial_days' => $result['trial_days'],
            'trial_ends_at' => $result['trial_ends_at'],
            'trial_plan' => $result['trial_plan'],
            'trial_support' => $signups->trialSupport(),
            'login_url' => $result['login_url'],
        ], 201);
    }
}
