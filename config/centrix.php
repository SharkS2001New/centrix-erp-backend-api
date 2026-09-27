<?php

/**
 * Centrix product release metadata.
 * VERSION at the repo root is the source of truth (semver X.Y.Z).
 * CI bumps the patch on every push to main/master.
 */
return [
    'version' => trim((string) (
        is_readable(base_path('VERSION'))
            ? file_get_contents(base_path('VERSION'))
            : (env('CENTRIX_VERSION') ?: '0.0.0')
    )),
    'version_file' => 'VERSION',
];
