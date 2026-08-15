#!/usr/bin/env bash
# Checks the platform scheduler heartbeat cache key.
PROJECT_DIR="${1:-$(pwd)}"
php ${PROJECT_DIR}/artisan tinker --execute="echo \Illuminate\Support\Facades\Cache::get(\App\Services\Platform\PlatformHealthProbe::SCHEDULER_HEARTBEAT_KEY) ?: 'no-heartbeat';" 
