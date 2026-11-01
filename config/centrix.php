<?php

/**
 * Centrix product release metadata.
 * VERSION at the repo root is the source of truth (semver X.Y.Z).
 * Update it with release changes before pushing them for deployment.
 */
return [
    'version' => trim((string) (
        is_readable(base_path('VERSION'))
            ? file_get_contents(base_path('VERSION'))
            : (env('CENTRIX_VERSION') ?: '0.0.0')
    )),
    'version_file' => 'VERSION',
];
