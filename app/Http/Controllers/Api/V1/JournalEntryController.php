<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ActionRequest;
use App\Models\JournalEntry;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        $this->access()->applyBranchListFilter($query, $request->user(), $request);

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if ($col === 'branch_id') {
                continue; // already applied via applyBranchListFilter
            }
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

        $pendingRequest = ActionRequest::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('type', 'journal_entry')
            ->where('reference_type', 'journal_entry')
            ->where('reference_id', (int) $entry->id)
            ->where('status', 'pending')
            ->first();

        $entry->setAttribute(
            'action_request',
            app(ActionRequestService::class)->presentForViewer($pendingRequest, $request->user()),
        );

        return response()->json($entry);
    }

    public function store(Request $request)
    {
        throw ValidationException::withMessages([
            'journal_entry' => ['Use POST /accounting/journal-entries to create journal entries.'],
        ]);
    }

    public function update(Request $request, string $id)
    {
        $this->findOrgEntry($request, $id);

        throw ValidationException::withMessages([
            'journal_entry' => ['Journal entries cannot be edited directly. Post, reverse, or delete draft entries via the accounting API.'],
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $entry = $this->findOrgEntry($request, $id);

        if ($entry->status !== 'draft') {
            return response()->json(['message' => 'Only draft entries can be deleted.'], 422);
        }

        $entry->delete();

        return response()->json(null, 204);
    }

    protected function findOrgEntry(Request $request, string $id): JournalEntry
    {
        return JournalEntry::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where($this->routeKeyColumn(), $id)
            ->firstOrFail();
    }
}
