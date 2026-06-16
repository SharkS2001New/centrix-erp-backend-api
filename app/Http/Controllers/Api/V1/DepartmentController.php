<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return Department::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('department_name', 'like', "%{$q}%")
                ->orWhere('department_code', 'like', "%{$q}%");
        });
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        $data = $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'department_code' => ($updating ? 'sometimes|' : 'nullable|') . 'string|max:45',
            'department_name' => $req . 'string|max:200',
            'is_active' => 'nullable|boolean',
        ]);

        if (empty($data['department_code']) && ! empty($data['department_name'])) {
            $data['department_code'] = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', $data['department_name']));
            $data['department_code'] = trim($data['department_code'], '-') ?: 'DEPT';
        }

        return $data;
    }
}
