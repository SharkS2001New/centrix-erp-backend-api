<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\JournalEntry;
use Illuminate\Http\Request;

class JournalEntryController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return JournalEntry::class;
    }

    public function index(Request $request)
    {
        $query = JournalEntry::query()
            ->where('organization_id', $request->user()->organization_id);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('entry_number', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderByDesc('entry_date')->orderByDesc('id')->paginate($perPage),
        );
    }

    public function show(Request $request, string $id)
    {
        $entry = JournalEntry::query()
            ->with(['lines.account'])
            ->where('organization_id', $request->user()->organization_id)
            ->where($this->routeKeyColumn(), $id)
            ->firstOrFail();

        return response()->json($entry);
    }

    public function destroy(Request $request, string $id)
    {
        $entry = JournalEntry::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where($this->routeKeyColumn(), $id)
            ->firstOrFail();

        if ($entry->status !== 'draft') {
            return response()->json(['message' => 'Only draft entries can be deleted.'], 422);
        }

        $entry->delete();

        return response()->json(null, 204);
    }
}
