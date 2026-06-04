<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Position;
use Illuminate\Http\Request;

class PositionController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return Position::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('position_title', 'like', "%{$q}%")
                ->orWhere('position_code', 'like', "%{$q}%");
        });
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        $data = $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'position_code' => ($updating ? 'sometimes|' : 'nullable|') . 'string|max:45',
            'position_title' => $req . 'string|max:200',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        if (empty($data['position_code']) && ! empty($data['position_title'])) {
            $data['position_code'] = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', $data['position_title']));
            $data['position_code'] = trim($data['position_code'], '-') ?: 'POS';
        }

        return $data;
    }
}
