<?php

return [
    'graph_api_version' => env('WHATSAPP_GRAPH_API_VERSION', 'v21.0'),

    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    'app_secret' => env('WHATSAPP_APP_SECRET'),

    /** Fallback when no whatsapp_configs row exists (single-tenant dev). */
    'organization_id' => env('WHATSAPP_ORGANIZATION_ID'),

    'bot_user_id' => env('WHATSAPP_BOT_USER_ID'),

    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),

    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),

    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '254'),

    'conversation_ttl_hours' => (int) env('WHATSAPP_CONVERSATION_TTL_HOURS', 24),
];
