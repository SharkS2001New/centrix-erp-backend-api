<?php

namespace App\Services\Retention;

use App\Services\Backup\BackupScheduleSettingsResolver;

/**
 * Platform-admin overrides for operational data retention (stored on PLATFORM org).
 */
class DataRetentionSettingsResolver
{
    public const MODULE_KEY = 'platform_data_retention';

    /** @return list<string> */
    public static function settingKeys(): array
    {
        return [
            'hikvision_access_events_days',
            'attendance_days',
            'hikvision_agent_commands_completed_days',
            'hikvision_agent_commands_failed_days',
            'kra_agent_commands_completed_days',
            'kra_agent_commands_failed_days',
            'released_stock_reservations_days',
            'audit_logs_days',
            'cancelled_sales_days',
            'expired_sales_days',
            'prune_time',
        ];
    }

    /** @return array<string, int|string> */
    public static function defaults(): array
    {
        return [
            'hikvision_access_events_days' => max(1, (int) config('data_retention.hikvision_access_events_days', 7)),
            'attendance_days' => max(30, (int) config('data_retention.attendance_days', 60)),
            'hikvision_agent_commands_completed_days' => max(1, (int) config('data_retention.hikvision_agent_commands_completed_days', 1)),
            'hikvision_agent_commands_failed_days' => max(1, (int) config('data_retention.hikvision_agent_commands_failed_days', 2)),
            // Match Hikvision completed default so agent command junk does not pile up.
            'kra_agent_commands_completed_days' => max(1, (int) config('data_retention.kra_agent_commands_completed_days', 1)),
            'kra_agent_commands_failed_days' => max(1, (int) config('data_retention.kra_agent_commands_failed_days', 2)),
            'released_stock_reservations_days' => max(1, (int) config('data_retention.released_stock_reservations_days', 14)),
            'audit_logs_days' => max(1, (int) config('data_retention.audit_logs_days', 10)),
            'cancelled_sales_days' => max(1, (int) config('data_retention.cancelled_sales_days', 7)),
            'expired_sales_days' => max(1, (int) config('data_retention.expired_sales_days', 14)),
            'prune_time' => self::normalizeTime((string) config('data_retention.prune_time', '03:40')),
        ];
    }

    /** @return array<string, int|string> */
    public static function resolve(): array
    {
        $org = BackupScheduleSettingsResolver::platformOrganization();
        $stored = is_array($org?->module_settings[self::MODULE_KEY] ?? null)
            ? $org->module_settings[self::MODULE_KEY]
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, int|string>
     */
    public static function update(array $incoming): array
    {
        $org = BackupScheduleSettingsResolver::platformOrganization(true);
        if (! $org) {
            throw new \RuntimeException('Platform organization is not configured.');
        }

        $moduleSettings = $org->module_settings ?? [];
        $current = is_array($moduleSettings[self::MODULE_KEY] ?? null)
            ? $moduleSettings[self::MODULE_KEY]
            : [];
        $moduleSettings[self::MODULE_KEY] = self::normalize(array_merge(self::defaults(), $current, $incoming));
        $org->update(['module_settings' => $moduleSettings]);

        BackupScheduleSettingsResolver::platformOrganization(true);
        self::applyToRuntime($moduleSettings[self::MODULE_KEY]);

        return $moduleSettings[self::MODULE_KEY];
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public static function applyToRuntime(?array $settings = null): void
    {
        $settings = self::normalize($settings ?? self::resolve());
        foreach (self::settingKeys() as $key) {
            if ($key === 'prune_time') {
                config(['data_retention.prune_time' => $settings['prune_time']]);

                continue;
            }
            config(["data_retention.{$key}" => (int) $settings[$key]]);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, int|string>
     */
    public static function normalize(array $settings): array
    {
        return [
            'hikvision_access_events_days' => max(1, min(90, (int) ($settings['hikvision_access_events_days'] ?? 7))),
            'attendance_days' => max(30, min(365, (int) ($settings['attendance_days'] ?? 60))),
            'hikvision_agent_commands_completed_days' => max(1, min(90, (int) ($settings['hikvision_agent_commands_completed_days'] ?? 1))),
            'hikvision_agent_commands_failed_days' => max(1, min(90, (int) ($settings['hikvision_agent_commands_failed_days'] ?? 2))),
            'kra_agent_commands_completed_days' => max(1, min(90, (int) ($settings['kra_agent_commands_completed_days'] ?? 1))),
            'kra_agent_commands_failed_days' => max(1, min(90, (int) ($settings['kra_agent_commands_failed_days'] ?? 2))),
            'released_stock_reservations_days' => max(1, min(90, (int) ($settings['released_stock_reservations_days'] ?? 14))),
            'audit_logs_days' => max(1, min(90, (int) ($settings['audit_logs_days'] ?? 10))),
            'cancelled_sales_days' => max(1, min(90, (int) ($settings['cancelled_sales_days'] ?? 7))),
            'expired_sales_days' => max(1, min(90, (int) ($settings['expired_sales_days'] ?? 14))),
            'prune_time' => self::normalizeTime((string) ($settings['prune_time'] ?? '03:40')),
        ];
    }

    protected static function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return '03:40';
        }

        return $time;
    }
}
