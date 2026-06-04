<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

class AttendanceClockDeviceController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return \App\Models\AttendanceClockDevice::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('device_no', 'like', "%{$q}%")
                ->orWhere('location', 'like', "%{$q}%");
        });
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'device_no' => $req . 'string|max:50',
            'location' => 'nullable|string|max:200',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
