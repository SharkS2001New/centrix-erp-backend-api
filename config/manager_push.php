<?php

return [
  // Shared FCM config for Centrix Manager + Centrix Mobile field sales apps.
    'enabled' => (bool) env('MANAGER_PUSH_ENABLED', false),

    /** Firebase project id (FCM HTTP v1). */
    'fcm_project_id' => env('FCM_PROJECT_ID'),

    /**
     * Absolute path to a Google service account JSON file with
     * Firebase Cloud Messaging API enabled.
     */
    'fcm_credentials_path' => env('FCM_CREDENTIALS_PATH'),

    /** Skip tokens that look like local dev placeholders. */
    'ignore_local_tokens' => (bool) env('MANAGER_PUSH_IGNORE_LOCAL_TOKENS', true),
];
