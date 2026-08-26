<?php

return [
    /**
     * Active provider for tool-calling chat (AiToolChatService).
     * openai | gemini
     */
    'provider' => env('AI_PROVIDER', 'openai'),

    'enabled' => filter_var(env('AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /**
     * Workspace IDs accepted on AI chat / training (mirrors erp_workspaces + hospitality).
     * Used for request validation and knowledge scoping.
     */
    'workspace_ids' => [
        'pos',
        'backoffice',
        'hotel_bar_pos',
        'hospitality_backoffice',
        'admin',
        'accounting',
        'hr',
        'distribution',
    ],

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

    /** When true, OpenAI-compatible providers also use tool-calling chat (Gemini always does). */
    'use_tool_chat' => filter_var(env('AI_USE_TOOL_CHAT', true), FILTER_VALIDATE_BOOLEAN),

    'request_timeout' => (int) env('AI_REQUEST_TIMEOUT', 60),
    // Allow a follow-up tool round for multi-part questions (sales + expenses + returns).
    'max_tool_rounds' => (int) env('AI_MAX_TOOL_ROUNDS', 3),

    /**
     * Trim prompts, history, and tool loops for lower latency (recommended for production chat).
     * Fast mode still allows 2 tool rounds so compound questions can call several tools.
     */
    'fast_mode' => filter_var(env('AI_FAST_MODE', true), FILTER_VALIDATE_BOOLEAN),
    'fast_mode_max_tool_rounds' => (int) env('AI_FAST_MODE_MAX_TOOL_ROUNDS', 2),

    /** Stream token deltas to the web assistant via POST /ai/chat/stream (OpenAI-compatible providers). */
    'stream_responses' => filter_var(env('AI_STREAM_RESPONSES', true), FILTER_VALIDATE_BOOLEAN),

    /** Cap simultaneous in-flight inferences (0 = unlimited). Only rejects under extreme concurrent load. */
    'max_concurrent_requests' => (int) env('AI_MAX_CONCURRENT_REQUESTS', 32),

    'tool_chat' => [
        // Product/table answers need headroom; 1024 commonly cut mid-sentence (finish_reason=length).
        'max_output_tokens' => (int) env('AI_TOOL_CHAT_MAX_OUTPUT_TOKENS', env('AI_MAX_OUTPUT_TOKENS', 4096)),
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
        'get_vat_collected' => true,
        'get_sales_by_cashier' => true,
        'get_sales_by_product' => true,
        'get_sales_brief' => true,
        'get_stock_summary' => true,
        'get_product_details' => true,
        'get_product_price_history' => true,
        'search_training_notes' => true,
        'get_purchasing_overview' => true,
        'get_lpo_details' => true,
        'get_debtors_summary' => true,
        'get_customer_statement' => true,
        'get_supplier_statement' => true,
        'get_till_health' => true,
        'get_route_orders' => true,
        'get_customer_returns' => true,
        'get_route_details' => true,
        'get_user_details' => true,
        'get_employee_attendance' => true,
        'get_employee_details' => true,
        'get_employee_payroll_preview' => true,
        'create_custom_report' => true,
        'run_insight' => true,
        'get_profit_loss' => true,
        'get_expense_summary' => true,
        'get_customer_portfolio' => true,
        'get_inventory_valuation' => true,
        'get_cash_position' => true,
        'calculate_scenario' => true,
    ],
    'conversation_history_limit' => (int) env('AI_CONVERSATION_HISTORY_LIMIT', 8),

    /**
     * Create/write actions that may show an inline form immediately (exceptions).
     * All other create_* and record_customer_payment actions defer the form until
     * the user replies "show form" or validation fails on confirm.
     */
    'immediate_form_create_actions' => [],

    /** Application-level rate limit for POST /ai/chat (per user). */
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

    /**
     * Approximate USD list prices per 1M tokens for platform usage estimates.
     * Override via config if provider billing changes; stored cost still wins when logged.
     */
    'pricing' => [
        'currency' => 'USD',
        /** Indicative USD→KES for usage display; not a live CBK rate. */
        'usd_to_kes' => (float) env('AI_USD_TO_KES', 129),
        'per_million' => [
            'openai' => [
                'default' => ['input' => 0.15, 'output' => 0.60],
                'models' => [
                    'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
                    'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
                    'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
                    'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
                ],
            ],
            'gemini' => [
                'default' => ['input' => 0.10, 'output' => 0.40],
                'models' => [
                    'gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40],
                    'gemini-2.5-flash' => ['input' => 0.15, 'output' => 0.60],
                    'gemini-3.6-flash' => ['input' => 0.15, 'output' => 0.60],
                    'gemini-3.7-flash' => ['input' => 0.15, 'output' => 0.60],
                ],
            ],
        ],
    ],
];
