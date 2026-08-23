<?php

return [
    /**
     * Active provider for tool-calling chat (AiToolChatService).
     * openai | gemini  (groq / openrouter / ollama reserved)
     */
    'provider' => env('AI_PROVIDER', 'openai'),

    'enabled' => filter_var(env('AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /** Default model/URL when an org leaves them blank in Settings → AI. */
    'defaults' => [
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1200),
        'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', env('OPENAI_MAX_TOKENS', 2048)),
        'max_input_tokens' => (int) env('AI_MAX_INPUT_TOKENS', 32000),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'base_url' => rtrim(env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
    ],

    /**
     * Which provider platform offers free to selected orgs (overridable in Platform → AI credentials).
     * gemini | openai
     */
    'free_provider' => env('AI_FREE_PROVIDER', 'gemini'),

    /** When true, OpenAI provider also uses tool-calling chat (Gemini always does). */
    'use_tool_chat' => filter_var(env('AI_USE_TOOL_CHAT', false), FILTER_VALIDATE_BOOLEAN),

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),
    'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 3),
    'conversation_history_limit' => (int) env('AI_CONVERSATION_HISTORY_LIMIT', 12),

    /** Application-level rate limit for POST /ai/chat (per user). */
    'rate_limit' => [
        'max_attempts' => (int) env('AI_RATE_LIMIT', 90),
        'decay_minutes' => (int) env('AI_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

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
