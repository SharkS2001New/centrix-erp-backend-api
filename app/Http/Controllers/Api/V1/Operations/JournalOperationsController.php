<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalOperationsController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_number' => 'required|string',
            'entry_date' => 'required|date',
            'description' => 'nullable|string',
            'branch_id' => 'nullable|integer',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|integer',
            'lines.*.debit' => 'nullable|numeric',
            'lines.*.credit' => 'nullable|numeric',
        ]);

        $debits = collect($data['lines'])->sum('debit');
        $credits = collect($data['lines'])->sum('credit');
        if (round($debits, 2) !== round($credits, 2)) {
            return response()->json(['message' => 'Debits must equal credits.'], 422);
        }

        return DB::transaction(function () use ($data, $request) {
            $entry = JournalEntry::create([
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $data['branch_id'] ?? null,
                'entry_number' => $data['entry_number'],
                'entry_date' => $data['entry_date'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['lines'] as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'line_notes' => $line['line_notes'] ?? null,
                ]);
            }

            return response()->json($entry->load('lines'), 201);
        });
    }

    public function post(Request $request, int $entryId)
    {
        $entry = JournalEntry::findOrFail($entryId);
        $entry->update(['status' => 'posted', 'posted_at' => now()]);
        return response()->json($entry);
    }
}
