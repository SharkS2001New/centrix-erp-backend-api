<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payroll\KenyaPayrollSettingsResolver;
use Illuminate\Http\Request;

class PlatformKenyaPayrollSettingsController extends Controller
{
    public function show()
    {
        return response()->json(KenyaPayrollSettingsResolver::describe());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'effective_label' => 'sometimes|nullable|string|max:40',
            'paye' => 'sometimes|array',
            'paye.personal_relief_monthly' => 'sometimes|numeric|min:0|max:100000',
            'paye.insurance_relief_rate' => 'sometimes|numeric|min:0|max:1',
            'paye.insurance_relief_cap_monthly' => 'sometimes|numeric|min:0|max:100000',
            'paye.bands' => 'sometimes|array|min:1|max:12',
            'paye.bands.*.up_to' => 'nullable|numeric|min:0',
            'paye.bands.*.rate' => 'required_with:paye.bands|numeric|min:0|max:1',
            'nssf' => 'sometimes|array',
            'nssf.rate' => 'sometimes|numeric|min:0|max:1',
            'nssf.tier1_upper' => 'sometimes|numeric|min:0',
            'nssf.tier2_upper' => 'sometimes|numeric|min:0',
            'shif' => 'sometimes|array',
            'shif.rate' => 'sometimes|numeric|min:0|max:1',
            'shif.minimum_monthly' => 'sometimes|numeric|min:0',
            'housing_levy' => 'sometimes|array',
            'housing_levy.employee_rate' => 'sometimes|numeric|min:0|max:1',
            'housing_levy.employer_rate' => 'sometimes|numeric|min:0|max:1',
            'reset_to_defaults' => 'sometimes|boolean',
        ]);

        if (! empty($data['reset_to_defaults'])) {
            return response()->json(KenyaPayrollSettingsResolver::resetToDefaults());
        }

        unset($data['reset_to_defaults']);

        return response()->json(KenyaPayrollSettingsResolver::save($data));
    }
}
