<?php
namespace App\Providers;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Observers\OrganizationObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Organization::observe(OrganizationObserver::class);

        $this->configureRateLimiting();
        $this->configureCorsFromRuntimeEnv();

        if (
            $this->app->environment('production')
            && ! config('database.allow_destructive_commands', false)
        ) {
            DB::prohibitDestructiveCommands();
        }
    }

    protected function configureRateLimiting(): void
    {
        $login = config('security.rate_limits.auth_login');
        RateLimiter::for('auth-login', function (Request $request) use ($login) {
            $username = strtolower(trim((string) $request->input('username', '')));

            return [
                Limit::perMinutes(
                    max(1, (int) ($login['decay_minutes'] ?? 1)),
                    max(1, (int) ($login['max_attempts'] ?? 5)),
                )->by($request->ip()),
                Limit::perMinutes(
                    max(1, (int) ($login['decay_minutes'] ?? 1)),
                    max(1, (int) ($login['max_attempts'] ?? 5)) * 2,
                )->by($username !== '' ? 'user:'.$username : 'ip:'.$request->ip()),
            ];
        });

        $password = config('security.rate_limits.auth_password');
        RateLimiter::for('auth-password', function (Request $request) use ($password) {
            return Limit::perMinutes(
                max(1, (int) ($password['decay_minutes'] ?? 15)),
                max(1, (int) ($password['max_attempts'] ?? 5)),
            )->by($request->ip());
        });

        $preview = config('security.rate_limits.auth_org_preview');
        RateLimiter::for('auth-org-preview', function (Request $request) use ($preview) {
            return Limit::perMinutes(
                max(1, (int) ($preview['decay_minutes'] ?? 1)),
                max(1, (int) ($preview['max_attempts'] ?? 20)),
            )->by($request->ip());
        });

        $api = config('security.rate_limits.api');
        RateLimiter::for('api', function (Request $request) use ($api) {
            $key = $request->user()?->id
                ? 'user:'.$request->user()->id
                : 'ip:'.$request->ip();

            return Limit::perMinutes(
                max(1, (int) ($api['decay_minutes'] ?? 1)),
                max(1, (int) ($api['max_attempts'] ?? 120)),
            )->by($key);
        });
    }

    /**
     * Override cached CORS config from runtime env (k8s secrets survive config:cache).
     */
    protected function configureCorsFromRuntimeEnv(): void
    {
        $raw = getenv('CORS_ALLOWED_ORIGINS') ?: getenv('FRONTEND_URL');
        if (! is_string($raw) || trim($raw) === '') {
            return;
        }

        $origins = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($origins !== []) {
            config(['cors.allowed_origins' => $origins]);
        }
    }
}
