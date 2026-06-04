<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

class OrganizationHolidayController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return \App\Models\OrganizationHoliday::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where('name', 'like', "%{$q}%");
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'holiday_date' => $req . 'date',
            'name' => $req . 'string|max:200',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
