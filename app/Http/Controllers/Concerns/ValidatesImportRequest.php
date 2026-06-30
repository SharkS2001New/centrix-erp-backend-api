<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ValidatesImportRequest
{
    /** @return array{rows: array<int, array<string, mixed>>} */
    protected function validateImportRows(Request $request, int $maxRows = 5000): array
    {
        return $request->validate([
            'rows' => ['required', 'array', 'min:1', "max:{$maxRows}"],
        ]);
    }
}
