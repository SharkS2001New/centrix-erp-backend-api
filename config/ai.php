<?php

return [
    /** Default model/URL when an org leaves them blank in Settings → AI. */
    'defaults' => [
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1200),
    ],

    'provider' => env('AI_PROVIDER', 'openai'),

    /**
     * Optional server-side fallback for Platform → AI training (super-admin console).
     * Prefer credentials saved via /admin/ai-training/settings; env is for bootstrap/CI only.
     */
    'platform_training' => [
        'api_key' => env('PLATFORM_OPENAI_API_KEY', ''),
        'model' => env('PLATFORM_OPENAI_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
        'base_url' => rtrim(env('PLATFORM_OPENAI_BASE_URL', env('OPENAI_BASE_URL', 'https://api.openai.com/v1')), '/'),
    ],
];
