<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

class WorkShiftController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return \App\Models\WorkShift::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('shift_name', 'like', "%{$q}%")
                ->orWhere('shift_code', 'like', "%{$q}%");
        });
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        $data = $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'shift_code' => ($updating ? 'sometimes|' : 'nullable|') . 'string|max:45',
            'shift_name' => $req . 'string|max:120',
            'start_time' => $req . 'date_format:H:i:s',
            'end_time' => $req . 'date_format:H:i:s',
            'crosses_midnight' => 'nullable|boolean',
            'works_saturday' => 'nullable|boolean',
            'works_sunday' => 'nullable|boolean',
            'works_public_holidays' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        foreach (['start_time', 'end_time'] as $k) {
            if (! empty($data[$k]) && preg_match('/^\d{2}:\d{2}$/', $data[$k])) {
                $data[$k] .= ':00';
            }
        }

        if (empty($data['shift_code']) && ! empty($data['shift_name'])) {
            $data['shift_code'] = strtoupper(substr(preg_replace('/[^A-Z0-9]+/', '-', $data['shift_name']), 0, 40), '-');
        }

        return $data;
    }
}
