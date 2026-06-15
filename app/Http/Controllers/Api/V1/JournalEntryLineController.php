<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class JournalEntryLineController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return JournalEntryLine::class;
    }

    protected function baseQuery(Request $request): Builder
    {
        $orgId = (int) $request->user()->organization_id;

        return JournalEntryLine::query()
            ->select('journal_entry_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.organization_id', $orgId);
    }

    public function store(Request $request)
    {
        throw ValidationException::withMessages([
            'journal_entry_line' => ['Journal lines are managed through POST /accounting/journal-entries.'],
        ]);
    }

    public function update(Request $request, string $id)
    {
        throw ValidationException::withMessages([
            'journal_entry_line' => ['Journal lines cannot be edited directly.'],
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        throw ValidationException::withMessages([
            'journal_entry_line' => ['Journal lines cannot be deleted directly. Delete the draft journal entry instead.'],
        ]);
    }
}
