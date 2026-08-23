<?php

return [
    /**
     * Active provider for tool-calling chat (AiToolChatService).
     * openai | gemini | ollama
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
     * Self-hosted Ollama (completely free). Run as a k8s/docker pod and point base_url at it.
     * Example: http://centrix-erp-ollama:11434
     */
    'ollama' => [
        'api_key' => env('OLLAMA_API_KEY', 'ollama'),
        'model' => env('OLLAMA_MODEL', 'llama3.2'),
        'base_url' => rtrim(env('OLLAMA_BASE_URL', 'http://ollama:11434'), '/'),
        'request_timeout' => (int) env('OLLAMA_TIMEOUT', env('OLLAMA_REQUEST_TIMEOUT', 120)),
    ],

    /**
     * Which provider platform offers free to selected orgs (overridable in Platform → AI credentials).
     * gemini | openai | ollama
     */
    'free_provider' => env('AI_FREE_PROVIDER', 'gemini'),

    /** When true, OpenAI provider also uses tool-calling chat (Gemini/Ollama always do). */
    'use_tool_chat' => filter_var(env('AI_USE_TOOL_CHAT', false), FILTER_VALIDATE_BOOLEAN),

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),
    'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 2),

    /** Cap simultaneous in-flight inferences (0 = unlimited). Only rejects under extreme concurrent load. */
    'max_concurrent_requests' => (int) env('AI_MAX_CONCURRENT_REQUESTS', 32),

    'tool_chat' => [
        'max_output_tokens' => (int) env('AI_TOOL_CHAT_MAX_OUTPUT_TOKENS', env('AI_MAX_OUTPUT_TOKENS', 1024)),
    ],

    /**
     * Privacy-aware usage logging (Platform Admin). Never logs secrets/credentials.
     */
    'logging' => [
        'async' => filter_var(env('AI_LOG_ASYNC', true), FILTER_VALIDATE_BOOLEAN),
        'prompts' => filter_var(env('AI_LOG_PROMPTS', true), FILTER_VALIDATE_BOOLEAN),
        'responses' => filter_var(env('AI_LOG_RESPONSES', true), FILTER_VALIDATE_BOOLEAN),
        'database_queries' => filter_var(env('AI_LOG_DATABASE_QUERIES', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /**
     * Default enablement for assistant tools (overridable per org in module_settings.ai.tools).
     * Platform can roll out modules by flipping these for selected tenants.
     */
    'tools' => [
        'find_screen' => true,
        'get_sales_summary' => true,
        'get_sales_by_cashier' => true,
        'get_sales_brief' => true,
        'get_stock_summary' => true,
        'get_purchasing_overview' => true,
        'get_debtors_summary' => true,
        'get_till_health' => true,
        'get_route_orders' => true,
    ],
    'conversation_history_limit' => (int) env('AI_CONVERSATION_HISTORY_LIMIT', 12),

    /** Application-level rate limit for POST /ai/chat (per user). Skipped entirely when provider is Ollama. */
    'rate_limit' => [
        'max_attempts' => (int) env('AI_RATE_LIMIT', 90),
        'platform_max_attempts' => (int) env('AI_PLATFORM_RATE_LIMIT', 180),
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
