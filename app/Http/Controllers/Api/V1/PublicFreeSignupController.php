<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\FreePlanAccess;
use App\Services\Platform\PublicFreeSignupService;
use Illuminate\Http\Request;

/**
 * Instant free-package signup from the Centrix marketing site (Nextcloud-style).
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

        return response()->json([
            'enabled' => true,
            'free_plan_name' => $freePlan->name(),
            'industries' => $signups->industryOptions(),
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
            'industry' => 'required|string|max:40',
            'admin_full_name' => 'required|string|max:200',
            'admin_password' => 'required|string|min:8|max:100',
        ]);

        $result = $signups->signup($data);

        return response()->json([
            'message' => 'Your free Centrix workspace is ready. Sign in with the company code and username below.',
            'company_code' => $result['company_code'],
            'username' => $result['username'],
            'org_name' => $result['organization']->org_name,
            'industry' => $result['industry'],
            'free_plan_name' => $result['free_plan_name'],
            'login_url' => $result['login_url'],
        ], 201);
    }
}
