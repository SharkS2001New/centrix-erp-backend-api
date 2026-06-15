<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalOperationsController extends Controller
{
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

        $this->assertBalanced($data['lines']);

        $orgId = (int) $request->user()->organization_id;
        if (JournalEntry::query()->where('organization_id', $orgId)->where('entry_number', $data['entry_number'])->exists()) {
            return response()->json(['message' => 'Entry number already exists.'], 422);
        }

        return DB::transaction(function () use ($data, $request, $orgId) {
            $entry = JournalEntry::create([
                'organization_id' => $orgId,
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

            return response()->json($entry->load(['lines.account']), 201);
        });
    }

    public function post(Request $request, int $entryId)
    {
        $entry = JournalEntry::with('lines')->findOrFail($entryId);

        if ((int) $entry->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        if ($entry->status !== 'draft') {
            return response()->json(['message' => 'Only draft entries can be posted.'], 422);
        }

        $this->assertBalanced($entry->lines->map(fn ($line) => [
            'debit' => $line->debit,
            'credit' => $line->credit,
        ])->all());

        $entry->update(['status' => 'posted', 'posted_at' => now()]);

        return response()->json($entry->fresh(['lines.account']));
    }

    public function reverse(Request $request, int $entryId)
    {
        $entry = JournalEntry::with('lines')->findOrFail($entryId);

        if ((int) $entry->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        if ($entry->status !== 'posted') {
            return response()->json(['message' => 'Only posted entries can be reversed.'], 422);
        }

        $orgId = (int) $entry->organization_id;
        $baseNumber = $entry->entry_number;
        $reversalNumber = $this->nextReversalNumber($orgId, $baseNumber);

        return DB::transaction(function () use ($entry, $request, $reversalNumber) {
            $reversal = JournalEntry::create([
                'organization_id' => $entry->organization_id,
                'branch_id' => $entry->branch_id,
                'entry_number' => $reversalNumber,
                'entry_date' => now()->toDateString(),
                'reference_type' => 'journal_reversal',
                'reference_id' => $entry->id,
                'description' => 'Reversal of ' . $entry->entry_number . ($entry->description ? ': ' . $entry->description : ''),
                'status' => 'posted',
                'created_by' => $request->user()->id,
                'posted_at' => now(),
            ]);

            foreach ($entry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'line_notes' => $line->line_notes,
                ]);
            }

            $entry->update(['status' => 'void']);

            return response()->json([
                'original' => $entry->fresh(['lines.account']),
                'reversal' => $reversal->load(['lines.account']),
            ]);
        });
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function assertBalanced(array $lines): void
    {
        $debits = collect($lines)->sum(fn ($line) => (float) ($line['debit'] ?? 0));
        $credits = collect($lines)->sum(fn ($line) => (float) ($line['credit'] ?? 0));

        if (round($debits, 2) !== round($credits, 2)) {
            throw ValidationException::withMessages([
                'lines' => 'Debits must equal credits.',
            ]);
        }

        if ($debits <= 0) {
            throw ValidationException::withMessages([
                'lines' => 'Entry total must be greater than zero.',
            ]);
        }
    }

    protected function nextReversalNumber(int $orgId, string $baseNumber): string
    {
        $candidate = $baseNumber . '-REV';
        $suffix = 1;

        while (JournalEntry::query()->where('organization_id', $orgId)->where('entry_number', $candidate)->exists()) {
            $suffix++;
            $candidate = $baseNumber . '-REV' . $suffix;
        }

        return $candidate;
    }
}
