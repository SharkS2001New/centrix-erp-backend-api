<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WorkShift;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkShiftController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return WorkShift::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('shift_name', 'like', "%{$q}%")
                ->orWhere('shift_code', 'like', "%{$q}%");
        });
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->user()?->organization_id && empty($data['organization_id'])) {
            $data['organization_id'] = $request->user()->organization_id;
        }

        try {
            $model = WorkShift::create($data);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'shift_code' => ['This shift code is already used in your organization.'],
            ]);
        }

        return response()->json($model, 201);
    }

    public function update(Request $request, string $id)
    {
        $model = $this->findScoped($id);

        try {
            $model->update($this->validated($request, updating: true, existing: $model));
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'shift_code' => ['This shift code is already used in your organization.'],
            ]);
        }

        return response()->json($model->fresh());
    }

    protected function validated(Request $request, bool $updating = false, ?WorkShift $existing = null): array
    {
        $req = $updating ? 'sometimes|' : 'required|';
        $orgId = (int) ($request->user()?->organization_id
            ?? $request->input('organization_id')
            ?? $existing?->organization_id
            ?? 0);

        $data = $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'shift_code' => [
                $updating ? 'sometimes' : 'nullable',
                'string',
                'max:45',
                Rule::unique('work_shifts', 'shift_code')
                    ->where(fn ($q) => $q->where('organization_id', $orgId))
                    ->ignore($existing?->id),
            ],
            'shift_name' => $req . 'string|max:120',
            'start_time' => $req . 'date_format:H:i,H:i:s',
            'end_time' => $req . 'date_format:H:i,H:i:s',
            'crosses_midnight' => 'nullable|boolean',
            'works_saturday' => 'nullable|boolean',
            'works_sunday' => 'nullable|boolean',
            'works_public_holidays' => 'nullable|boolean',
            'use_alternate_hours' => 'nullable|boolean',
            'alternate_start_time' => 'nullable|date_format:H:i,H:i:s',
            'alternate_end_time' => 'nullable|date_format:H:i,H:i:s',
            'alternate_crosses_midnight' => 'nullable|boolean',
            'lunch_minutes' => 'nullable|integer|min:0|max:240',
            'alternate_lunch_minutes' => 'nullable|integer|min:0|max:240',
            'alternate_lunch_required' => 'nullable|boolean',
            'lunch_required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ], [
            'shift_code.unique' => 'This shift code is already used in your organization.',
        ]);

        foreach (['start_time', 'end_time', 'alternate_start_time', 'alternate_end_time'] as $k) {
            if (! empty($data[$k]) && preg_match('/^\d{2}:\d{2}$/', $data[$k])) {
                $data[$k] .= ':00';
            }
        }

        if (empty($data['shift_code']) && ! empty($data['shift_name'])) {
            $data['shift_code'] = strtoupper(substr(preg_replace('/[^A-Z0-9]+/', '-', $data['shift_name']), 0, 40));
        }

        if (! empty($data['use_alternate_hours'])) {
            if (empty($data['alternate_start_time']) || empty($data['alternate_end_time'])) {
                throw ValidationException::withMessages([
                    'alternate_start_time' => ['Set alternate start and end times for Saturday / public holidays.'],
                ]);
            }
        }

        return $data;
    }
}
