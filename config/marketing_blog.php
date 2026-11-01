<?php

return [
    /** Public CentrixERP marketing site origin (no trailing slash), e.g. https://centrixerp.com */
    'website_url' => rtrim((string) env('MARKETING_BLOG_WEBSITE_URL', env('MARKETING_SITE_URL', '')), '/'),

    /** Shared secret (16+ chars) for website /api/clear-blog-* routes. */
    'cache_clear_key' => (string) env('MARKETING_BLOG_CACHE_CLEAR_KEY', env('BLOG_CACHE_CLEAR_KEY', '')),

    /** Words per minute for read_time estimates. */
    'read_words_per_minute' => (int) env('MARKETING_BLOG_READ_WPM', 200),
];
