<?php

namespace App\Services\Platform;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\Request;

/**
 * Area-based platform admin permissions.
 *
 * users.platform_permissions:
 *   null  → full access (bootstrap / owner accounts)
 *   ["*"] → explicit full access
 *   ["tenants","billing",…] → limited areas only
 */
class PlatformAdminPermissions
{
    public const FULL = '*';

    public const TENANTS = 'tenants';

    public const INTEGRATIONS = 'integrations';

    public const OPERATIONS = 'operations';

    public const BILLING = 'billing';

    public const SETTINGS = 'settings';

    public const ADMINS = 'admins';

    /** @return list<array{code: string, label: string, description: string}> */
    public static function catalog(): array
    {
        return [
            [
                'code' => self::TENANTS,
                'label' => 'Tenants',
                'description' => 'Organizations, provisioning, setup types, and tenant configuration.',
            ],
            [
                'code' => self::INTEGRATIONS,
                'label' => 'Integrations',
                'description' => 'AI training, AI usage, mailbox, and WhatsApp tools.',
            ],
            [
                'code' => self::OPERATIONS,
                'label' => 'Operations',
                'description' => 'Active users, ERP speed, system issues, what’s new, backups, and retention.',
            ],
            [
                'code' => self::BILLING,
                'label' => 'Billing & tools',
                'description' => 'Plans, invoices, subscriptions, licence payments, contracts, and marketing.',
            ],
            [
                'code' => self::SETTINGS,
                'label' => 'Platform settings',
                'description' => 'Email delivery, R2, theme, paybill, alerts, and related platform settings.',
            ],
            [
                'code' => self::ADMINS,
                'label' => 'Platform admins',
                'description' => 'Create and manage other platform administrator accounts and their permissions.',
            ],
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::catalog(), 'code');
    }

    public static function hasFullAccess(?array $permissions): bool
    {
        if ($permissions === null) {
            return true;
        }

        return in_array(self::FULL, $permissions, true);
    }

    public static function allows(?array $permissions, string $area): bool
    {
        if (self::hasFullAccess($permissions)) {
            return true;
        }

        $area = strtolower(trim($area));
        if ($area === '' || $area === self::FULL) {
            return self::hasFullAccess($permissions);
        }

        return in_array($area, $permissions ?? [], true);
    }

    public static function userAllows(User $user, string $area): bool
    {
        if (! $user->is_super_admin) {
            return false;
        }

        return self::allows($user->platform_permissions, $area);
    }

    public static function userHasTwoFactor(User $user): bool
    {
        return app(TwoFactorService::class)->isEnabled($user);
    }

    /**
     * Normalize incoming permission list for storage.
     * null = unrestricted; otherwise unique known codes (or ["*"]).
     *
     * @param  mixed  $value
     * @return list<string>|null
     */
    public static function normalizeForStorage(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $item) {
            $code = strtolower(trim((string) $item));
            if ($code === self::FULL) {
                return [self::FULL];
            }
            if (in_array($code, self::codes(), true)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Map an API path to a platform permission area (null = any platform admin).
     */
    public static function areaForPath(string $path): ?string
    {
        $path = '/'.ltrim($path, '/');
        $path = preg_replace('#^/api/v1/#', '/', $path) ?: $path;

        if (str_starts_with($path, '/admin/platform-admins')) {
            return self::ADMINS;
        }

        $map = [
            self::TENANTS => [
                '/admin/organizations',
                '/admin/setup-profiles',
                '/admin/setup-types',
            ],
            self::INTEGRATIONS => [
                '/admin/ai-training',
                '/admin/platform-ai',
                '/admin/ai-usage',
                '/admin/platform-mailbox',
                '/admin/mailbox',
                '/admin/whatsapp',
                '/admin/platform-whatsapp',
                '/admin/fcm',
                '/admin/platform-fcm',
            ],
            self::OPERATIONS => [
                '/admin/active-sessions',
                '/admin/platform-health',
                '/admin/platform-speed',
                '/admin/system-issues',
                '/admin/whats-new',
                '/admin/database-backups',
                '/admin/platform-database',
                '/admin/data-retention',
                '/admin/platform-data-retention',
            ],
            self::BILLING => [
                '/admin/platform-billing',
                '/admin/platform-plans',
                '/admin/platform-invoices',
                '/admin/platform-subscriptions',
                '/admin/licence-payments',
                '/admin/platform-licence',
                '/admin/platform-paybill',
                '/admin/contracts',
                '/admin/marketing',
                '/admin/platform-marketing',
            ],
            self::SETTINGS => [
                '/admin/platform-settings',
                '/admin/platform-mail',
                '/admin/platform-theme',
                '/admin/platform-r2',
                '/admin/public-media',
                '/admin/platform-alerts',
                '/admin/kenya-payroll',
                '/admin/platform-payroll',
                '/admin/demo-video',
            ],
        ];

        foreach ($map as $area => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    return $area;
                }
            }
        }

        // Unmapped platform admin routes still require is_super_admin + 2FA only.
        return null;
    }

    public static function areaForRequest(Request $request): ?string
    {
        return self::areaForPath($request->path());
    }

    /** @return array{full_access: bool, areas: list<string>, two_factor_enabled: bool, two_factor_required: bool} */
    public static function describeForUser(User $user): array
    {
        $perms = $user->platform_permissions;
        $full = self::hasFullAccess($perms);
        $twoFactor = self::userHasTwoFactor($user);

        return [
            'full_access' => $full,
            'areas' => $full ? self::codes() : array_values(array_intersect(self::codes(), $perms ?? [])),
            'two_factor_enabled' => $twoFactor,
            'two_factor_required' => true,
            'can_manage_admins' => self::allows($perms, self::ADMINS),
        ];
    }
}
