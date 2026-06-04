<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\JournalEntryLine;

class JournalEntryLineController extends BaseResourceController
{
    protected function modelClass(): string { return JournalEntryLine::class; }
}
