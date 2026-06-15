<?php

return [
    /** Default model/URL when an org leaves them blank in Settings → AI. */
    'defaults' => [
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1200),
    ],

    'provider' => env('AI_PROVIDER', 'openai'),
];
