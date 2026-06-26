<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemIssueReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformSystemIssueReportController extends Controller
{
    public function index(Request $request)
    {
        $query = SystemIssueReport::query()
            ->with([
                'organization:id,org_name,company_code',
                'user:id,username,first_name,last_name',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('kind') && $request->input('kind') !== 'all') {
            $query->where('kind', $request->input('kind'));
        }

        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->input('organization_id'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('message', 'like', "%{$q}%")
                    ->orWhere('user_notes', 'like', "%{$q}%")
                    ->orWhere('page_url', 'like', "%{$q}%")
                    ->orWhere('api_path', 'like', "%{$q}%")
                    ->orWhere('id', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        return response()->json($query->paginate($perPage));
    }

    public function show(string $id)
    {
        $report = SystemIssueReport::query()
            ->with([
                'organization:id,org_name,company_code',
                'user:id,username,first_name,last_name',
                'resolvedBy:id,username,first_name,last_name',
            ])
            ->findOrFail($id);

        return response()->json($report);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'acknowledged', 'resolved'])],
            'resolution_notes' => 'nullable|string|max:5000',
        ]);

        $report = SystemIssueReport::query()->findOrFail($id);
        $next = [];

        if (array_key_exists('status', $data)) {
            $next['status'] = $data['status'];
            if ($data['status'] === 'resolved') {
                $next['resolved_at'] = now();
                $next['resolved_by_user_id'] = $request->user()->id;
            } elseif ($report->status === 'resolved') {
                $next['resolved_at'] = null;
                $next['resolved_by_user_id'] = null;
            }
        }

        if (array_key_exists('resolution_notes', $data)) {
            $next['resolution_notes'] = $data['resolution_notes'];
        }

        $report->update($next);

        return response()->json($report->fresh([
            'organization:id,org_name,company_code',
            'user:id,username,first_name,last_name',
            'resolvedBy:id,username,first_name,last_name',
        ]));
    }

    public function summary()
    {
        $open = SystemIssueReport::query()->where('status', 'open')->count();
        $acknowledged = SystemIssueReport::query()->where('status', 'acknowledged')->count();
        $today = SystemIssueReport::query()->whereDate('created_at', today())->count();

        return response()->json([
            'open' => $open,
            'acknowledged' => $acknowledged,
            'today' => $today,
        ]);
    }
}
