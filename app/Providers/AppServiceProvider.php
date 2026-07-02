<?php
namespace App\Providers;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\Sale;
use App\Observers\OrganizationObserver;
use App\Observers\SaleObserver;
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
        Sale::observe(SaleObserver::class);

        $this->configureRateLimiting();
        $this->configureCorsFromRuntimeEnv();
        $this->enforceProductionSafety();

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
            $companyCode = strtoupper(trim((string) $request->input('company_code', '')));
            $decay = max(1, (int) ($login['decay_minutes'] ?? 1));
            $perUser = max(5, (int) ($login['max_attempts'] ?? 15));
            $perIp = max($perUser, (int) ($login['max_attempts_per_ip'] ?? 120));
            $orgUserKey = $companyCode !== '' && $username !== ''
                ? 'org-user:'.$companyCode.':'.$username
                : ($username !== '' ? 'user:'.$username : 'ip:'.$request->ip());

            return [
                Limit::perMinutes($decay, $perIp)->by('ip:'.$request->ip()),
                Limit::perMinutes($decay, $perUser)->by($orgUserKey),
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
            $decay = max(1, (int) ($preview['decay_minutes'] ?? 1));
            $max = max(1, (int) ($preview['max_attempts'] ?? 60));
            $companyCode = strtoupper(trim((string) $request->input('company_code', '')));

            $limits = [
                Limit::perMinutes($decay, $max)->by('ip:'.$request->ip()),
            ];

            if ($companyCode !== '') {
                $limits[] = Limit::perMinutes($decay, $max)->by('org:'.$companyCode);
            }

            return $limits;
        });

        $companyMobileAttendance = config('security.rate_limits.company_mobile_attendance');
        RateLimiter::for('company-mobile-attendance', function (Request $request) use ($companyMobileAttendance) {
            $decay = max(1, (int) ($companyMobileAttendance['decay_minutes'] ?? 1));
            $max = max(1, (int) ($companyMobileAttendance['max_attempts'] ?? 120));
            $companyCode = strtoupper(trim((string) $request->input('company_code', '')));
            $deviceId = trim((string) $request->input('device_identifier', ''));

            $key = $deviceId !== ''
                ? 'device:'.$deviceId
                : 'ip:'.$request->ip();

            if ($companyCode !== '') {
                $key .= ':org:'.$companyCode;
            }

            return Limit::perMinutes($decay, $max)->by($key);
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
        $raw = $this->readRuntimeEnv('CORS_ALLOWED_ORIGINS')
            ?: $this->readRuntimeEnv('FRONTEND_URL');
        if (is_string($raw) && trim($raw) !== '') {
            $origins = array_values(array_filter(array_map('trim', explode(',', $raw))));
            if ($origins !== []) {
                config(['cors.allowed_origins' => $origins]);
            }
        }

        $cookieAuth = $this->runtimeEnvBool('WEB_COOKIE_AUTH', (bool) config('security.api_token_cookie.enabled', false));
        if ($cookieAuth) {
            config(['security.api_token_cookie.enabled' => true]);
            config(['security.cors_supports_credentials' => true]);
            config(['cors.supports_credentials' => true]);
        } else {
            $credentials = $this->readRuntimeEnv('CORS_SUPPORTS_CREDENTIALS');
            if ($credentials !== null) {
                $supports = $this->runtimeEnvBool('CORS_SUPPORTS_CREDENTIALS', false);
                config(['security.cors_supports_credentials' => $supports]);
                config(['cors.supports_credentials' => $supports]);
            }
        }

        $idleRevoke = $this->readRuntimeEnv('AUTH_SERVER_IDLE_REVOKE');
        if ($idleRevoke !== null) {
            config(['security.revoke_idle_tokens' => $this->runtimeEnvBool('AUTH_SERVER_IDLE_REVOKE', false)]);
        }

        foreach ([
            'name' => 'API_TOKEN_COOKIE_NAME',
            'domain' => 'API_TOKEN_COOKIE_DOMAIN',
            'same_site' => 'API_TOKEN_COOKIE_SAME_SITE',
        ] as $configKey => $envKey) {
            $value = $this->readRuntimeEnv($envKey);
            if ($value !== null) {
                config(["security.api_token_cookie.{$configKey}" => $value]);
            }
        }

        $secure = $this->readRuntimeEnv('API_TOKEN_COOKIE_SECURE');
        if ($secure !== null) {
            config(['security.api_token_cookie.secure' => $this->runtimeEnvBool('API_TOKEN_COOKIE_SECURE', true)]);
        }
    }

    protected function runtimeEnvBool(string $key, bool $default = false): bool
    {
        $value = $this->readRuntimeEnv($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    protected function enforceProductionSafety(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        if (filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL)) {
            config(['app.debug' => false]);
            logger()->warning('APP_DEBUG was enabled in production and has been forced to false.');
        }
    }

    protected function readRuntimeEnv(string $key): ?string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            return $trimmed;
        }

        return null;
    }
}
