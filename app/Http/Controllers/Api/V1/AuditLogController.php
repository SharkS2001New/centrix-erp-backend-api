<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return AuditLog::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        foreach (['user_id', 'branch_id', 'table_name', 'action', 'record_id'] as $col) {
            if ($request->filled($col)) {
                $query->where($col, $request->input($col));
            }
            if ($request->filled("filter.{$col}")) {
                $query->where($col, $request->input("filter.{$col}"));
            }
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true) && $val !== '' && $val !== null) {
                $query->where($col, $val);
            }
        }

        if ($request->filled('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date').' 23:59:59');
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('table_name', 'like', "%{$q}%")
                    ->orWhere('record_id', 'like', "%{$q}%")
                    ->orWhere('action', 'like', "%{$q}%")
                    ->orWhere('ip_address', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json($query->orderByDesc('id')->paginate($perPage));
    }

    public function store(Request $request): Response
    {
        abort(403, 'Audit log entries are system-generated and cannot be created manually.');
    }

    public function update(Request $request, string $id): Response
    {
        abort(403, 'Audit log entries cannot be modified.');
    }

    public function destroy(Request $request, string $id): Response
    {
        abort(403, 'Audit log entries cannot be deleted.');
    }
}
