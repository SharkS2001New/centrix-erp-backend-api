<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\Request;

trait ParsesMultipartJsonFields
{
    /** @param  array<int, string>  $fields */
    protected function decodeMultipartJsonFields(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (! is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $request->merge([$field => $decoded]);
            }
        }
    }
}
