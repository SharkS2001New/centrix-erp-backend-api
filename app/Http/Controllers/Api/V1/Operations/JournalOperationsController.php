<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalEntryApprovalService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class JournalOperationsController extends Controller
{
    public function __construct(
        protected JournalPostingService $posting,
        protected JournalEntryApprovalService $approval,
        protected ErpContext $erp,
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

    public function requestPost(Request $request, int $entryId)
    {
        $entry = JournalEntry::with('lines')->findOrFail($entryId);

        if ((int) $entry->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        $gate = $this->erp->gateForUser($request->user());
        if (! $this->approval->approvalEnabled($gate)) {
            throw ValidationException::withMessages([
                'authorization' => 'Journal entry posting approval is not enabled.',
            ]);
        }

        $actionRequest = $this->approval->requestPost($request->user(), $entry);

        return response()->json([
            'message' => 'Journal entry submitted for posting approval.',
            'pending_approval' => true,
            'action_request_id' => (int) $actionRequest->id,
        ], 202);
    }

    public function post(Request $request, int $entryId)
    {
        $entry = JournalEntry::with('lines')->findOrFail($entryId);

        if ((int) $entry->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        $user = $request->user();
        $gate = $this->erp->gateForUser($user);

        if ($this->approval->approvalEnabled($gate) && ! $this->approval->canDirectPost($user)) {
            throw ValidationException::withMessages([
                'authorization' => 'You need manager approval to post journal entries.',
            ]);
        }

        $posted = $this->approval->postEntry($entry, $user);

        app(\App\Services\Notifications\ActionRequestService::class)->markResolvedFromDomain(
            'journal_entry',
            'journal_entry',
            (int) $posted->id,
            'approved',
            $user,
        );

        return response()->json($posted);
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
