<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class UserLoginChannelService
{
    public const BACKOFFICE = 'backoffice';

    public const POS = 'pos';

    public const MOBILE = 'mobile';

    /** @var list<string> */
    public const ALL = [
        self::BACKOFFICE,
        self::POS,
        self::MOBILE,
    ];

    /** @return list<string> */
    public function defaultChannels(): array
    {
        return self::ALL;
    }

    /** @param  list<string>|null  $channels */
    public function normalize(?array $channels): array
    {
        if ($channels === null || $channels === []) {
            return $this->defaultChannels();
        }

        $valid = array_values(array_unique(array_intersect($channels, self::ALL)));

        return $valid !== [] ? $valid : $this->defaultChannels();
    }

    /** @return list<string> */
    public function channelsFor(User $user): array
    {
        $stored = $user->login_channels;
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($stored) || $stored === []) {
            if ($user->is_mobile_user) {
                return [self::MOBILE];
            }

            return $this->defaultChannels();
        }

        return $this->normalize($stored);
    }

    public function assertCanLogin(User $user, string $channel): void
    {
        $channel = $this->normalizeChannel($channel);
        $allowed = $this->channelsFor($user);

        if (in_array($channel, $allowed, true)) {
            return;
        }

        // Unified web login issues a backoffice token; POS-only accounts use the same sign-in page.
        if ($channel === self::BACKOFFICE) {
            $webEligible = array_intersect($allowed, [self::BACKOFFICE, self::POS]);
            if ($webEligible !== []) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'login_channel' => [
                sprintf('This account is not allowed to sign in via %s.', $this->label($channel)),
            ],
        ]);
    }

    public function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        if (! in_array($channel, self::ALL, true)) {
            throw ValidationException::withMessages([
                'login_channel' => ['Invalid login channel.'],
            ]);
        }

        return $channel;
    }

    public function tokenCanAccessPath(string $tokenChannel, string $path): bool
    {
        $tokenChannel = $this->normalizeChannel($tokenChannel);
        $path = $this->normalizeApiPath($path);

        if ($tokenChannel === self::BACKOFFICE) {
            return true;
        }

        if ($tokenChannel === self::POS) {
            return $this->isPosAllowedPath($path);
        }

        return $this->isMobileAllowedPath($path);
    }

    /** @param  list<string>  $channels */
    public function syncLegacyMobileFlag(array $channels): bool
    {
        return in_array(self::MOBILE, $channels, true);
    }

    public function label(string $channel): string
    {
        return match ($channel) {
            self::BACKOFFICE => 'Backoffice',
            self::POS => 'POS',
            self::MOBILE => 'Mobile',
            default => $channel,
        };
    }

    protected function normalizeApiPath(string $path): string
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'api/v1/')) {
            return substr($path, 7);
        }

        return $path;
    }

    protected function isPosAllowedPath(string $path): bool
    {
        if ($this->isSharedAuthPath($path)) {
            return true;
        }

        $prefixes = [
            'pos/',
            'sales',
            'sales/carts',
            'sales/orders',
            'sales/loyalty-cards',
            'tills',
            'till-float-sessions',
            'branches',
            'routes',
            'products',
            'customers',
            'payment-methods',
            'vouchers',
            'loyalty-cards',
            'inventory/availability',
            'current-stock',
            'uoms',
            'vats',
            'retail-package-settings',
            'payments/',
        ];

        return $this->pathMatchesAny($path, $prefixes);
    }

    protected function isMobileAllowedPath(string $path): bool
    {
        if ($this->isSharedAuthPath($path)) {
            return true;
        }

        $prefixes = [
            'mobile/',
            'sales/carts',
            'sales/customers',
            'sales/orders',
            'sales/loyalty-cards',
            'branches',
            'customers',
            'products',
            'payment-methods',
            'loyalty-cards',
            'inventory/availability',
            'current-stock',
            'routes',
            'uoms',
            'vats',
            'retail-package-settings',
            'payments/',
        ];

        return $this->pathMatchesAny($path, $prefixes);
    }

    protected function isSharedAuthPath(string $path): bool
    {
        return str_starts_with($path, 'auth/')
            || $path === 'erp/capabilities'
            || $path === 'health';
    }

    /** @param  list<string>  $prefixes */
    protected function pathMatchesAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === rtrim($prefix, '/')) {
                return true;
            }
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
