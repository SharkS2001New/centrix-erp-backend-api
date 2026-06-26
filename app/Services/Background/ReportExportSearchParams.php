<?php

namespace App\Services\Background;

/**
 * Normalize report export / fetch query params so active UI filters are kept
 * but pagination cursors from the on-screen table are not.
 */
class ReportExportSearchParams
{
    /** @param  array<string, mixed>  $params */
    public static function sanitize(array $params): array
    {
        unset(
            $params['page'],
            $params['per_page'],
            $params['legacy_page'],
        );

        return $params;
    }
}
