<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PayrollDeductionType;
use Illuminate\Http\Request;

class PayrollDeductionTypeController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return PayrollDeductionType::class;
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'deduction_code' => $req . 'string|max:45',
            'name' => $req . 'string|max:200',
            'calc_type' => 'nullable|in:fixed,percentage',
            'default_amount' => 'nullable|numeric|min:0',
            'default_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'applies_to_all' => 'nullable|boolean',
        ]);
    }
}
