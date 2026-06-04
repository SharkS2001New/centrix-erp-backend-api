<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationLeaveSettings;
use Illuminate\Http\Request;

class OrganizationLeaveSettingsController extends Controller
{
    /** GET /organization-leave-settings */
    public function show(Request $request)
    {
        $orgId = (int) $request->user()?->organization_id;
        if (! $orgId) {
            return response()->json(['message' => 'Organization required.'], 403);
        }

        return response()->json(OrganizationLeaveSettings::forOrganization($orgId));
    }

    /** PUT /organization-leave-settings — admin only */
    public function update(Request $request)
    {
        if (! $request->user()?->is_admin) {
            return response()->json(['message' => 'Admin access required.'], 403);
        }

        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'annual_leave_days' => 'required|numeric|min:0|max:365',
            'monthly_accrual_days' => 'required|numeric|min:0|max:31',
            'months_for_full_annual' => 'required|integer|min:1|max:60',
            'sick_leave_days' => 'required|numeric|min:0|max:365',
            'sick_leave_full_pay_days' => 'required|numeric|min:0|max:365',
            'sick_leave_half_pay_days' => 'required|numeric|min:0|max:365',
            'months_before_sick_eligibility' => 'required|integer|min:0|max:60',
        ]);

        $settings = OrganizationLeaveSettings::forOrganization($orgId);
        $settings->update($data);

        return response()->json($settings->fresh());
    }
}
