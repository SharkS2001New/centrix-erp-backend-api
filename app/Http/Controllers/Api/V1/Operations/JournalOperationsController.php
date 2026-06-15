<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\Request;

class JournalOperationsController extends Controller
{
    public function __construct(
        protected JournalPostingService $posting,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_number' => 'required|string|max:50',
            'entry_date' => 'required|date',
            'description' => 'nullable|string',
            'branch_id' => 'nullable|integer',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|integer',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
            'lines.*.line_notes' => 'nullable|string|max:255',
        ]);

        $orgId = (int) $request->user()->organization_id;
        $entry = $this->posting->createDraft(
            orgId: $orgId,
            user: $request->user(),
            entryNumber: $data['entry_number'],
            entryDate: $data['entry_date'],
            lines: $data['lines'],
            description: $data['description'] ?? null,
            branchId: $data['branch_id'] ?? null,
        );

        return response()->json($entry, 201);
    }

    public function post(Request $request, int $entryId)
    {
        $entry = JournalEntry::with('lines')->findOrFail($entryId);

        if ((int) $entry->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        return response()->json($this->posting->postDraft($entry));
    }

    public function reverse(Request $request, int $entryId)
    {
        $entry = JournalEntry::with('lines')->findOrFail($entryId);

        if ((int) $entry->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        $result = $this->posting->reversePosted($entry, $request->user());

        return response()->json([
            'original' => $result['original'],
            'reversal' => $result['reversal'],
        ]);
    }
}
