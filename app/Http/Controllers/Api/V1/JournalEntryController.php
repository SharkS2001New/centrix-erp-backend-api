<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\JournalEntry;

class JournalEntryController extends BaseResourceController
{
    protected function modelClass(): string { return JournalEntry::class; }
}
