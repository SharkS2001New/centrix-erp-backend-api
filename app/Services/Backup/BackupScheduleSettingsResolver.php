<?php

namespace App\Services\Backup;

use App\Models\Organization;

class BackupScheduleSettingsResolver
{
    public const MODULE_KEY = 'platform_backup_schedule';

    public const FREQUENCIES = [
        'hourly',
        'every_6_hours',
        'every_12_hours',
        'daily',
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $frequency = strtolower(trim((string) config('backup.schedule_frequency', 'daily')));
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            $frequency = 'daily';
        }

        $time = (string) config('backup.schedule_time', '02:00');
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '02:00';
        }

        return [
            'enabled' => (bool) config('backup.enabled', true),
            'frequency' => $frequency,
            'schedule_time' => $time,
            'retention_days' => max(1, (int) config('backup.retention_days', 3)),
        ];
    }

    public static function platformOrganization(bool $refresh = false): ?Organization
    {
        static $organization = null;
        static $resolved = false;

        if ($refresh) {
            $resolved = false;
            $organization = null;
        }

        if ($resolved) {
            return $organization;
        }

        $resolved = true;
        $organization = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();

        return $organization;
    }

    /** @return array<string, mixed> */
    public static function resolve(): array
    {
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::MODULE_KEY] ?? null)
            ? $org->module_settings[self::MODULE_KEY]
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $settings = self::resolve();

        return [
            'settings' => $settings,
            'effective' => [
                'enabled' => (bool) $settings['enabled'],
                'frequency' => $settings['frequency'],
                'schedule_time' => $settings['schedule_time'],
                'retention_days' => (int) $settings['retention_days'],
                'cron' => self::cronExpression($settings),
                'label' => self::frequencyLabel($settings),
                'source' => self::sourceLabel(),
            ],
            'options' => [
                'frequencies' => [
                    ['value' => 'hourly', 'label' => 'Every hour'],
                    ['value' => 'every_6_hours', 'label' => 'Every 6 hours'],
                    ['value' => 'every_12_hours', 'label' => 'Every 12 hours'],
                    ['value' => 'daily', 'label' => 'Once per day'],
                ],
            ],
            'hints' => [
                'hourly' => 'Best RPO for production. Prefer 2–3 day retention so disk stays manageable.',
                'schedule_time' => 'For daily backups: local time of day. For hourly / 6h / 12h: only the minutes field is used (e.g. 02:15 → :15 past each run).',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function save(array $incoming): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(503, 'Platform organization is not configured.');
        }

        $moduleSettings = $org->module_settings ?? [];
        $current = self::resolve();
        $moduleSettings[self::MODULE_KEY] = self::normalize(array_merge($current, $incoming));
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(refresh: true);
        self::applyToRuntime();

        return self::describe();
    }

    public static function isEnabled(): bool
    {
        return (bool) self::resolve()['enabled'];
    }

    public static function retentionDays(): int
    {
        return max(1, (int) self::resolve()['retention_days']);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    public static function cronExpression(?array $settings = null): string
    {
        $settings = self::normalize($settings ?? self::resolve());
        [$hour, $minute] = self::parseTime($settings['schedule_time']);

        return match ($settings['frequency']) {
            'hourly' => sprintf('%d * * * *', $minute),
            'every_6_hours' => sprintf('%d */6 * * *', $minute),
            'every_12_hours' => sprintf('%d */12 * * *', $minute),
            default => sprintf('%d %d * * *', $minute, $hour),
        };
    }

    /** Push schedule retention / enabled into runtime config for prune + command checks. */
    public static function applyToRuntime(): void
    {
        $settings = self::resolve();
        config([
            'backup.enabled' => (bool) $settings['enabled'],
            'backup.retention_days' => (int) $settings['retention_days'],
            'backup.schedule_frequency' => $settings['frequency'],
            'backup.schedule_time' => $settings['schedule_time'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings): array
    {
        $frequency = strtolower(trim((string) ($settings['frequency'] ?? 'daily')));
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            $frequency = 'daily';
        }

        $time = trim((string) ($settings['schedule_time'] ?? '02:00'));
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '02:00';
        }

        return [
            'enabled' => (bool) ($settings['enabled'] ?? true),
            'frequency' => $frequency,
            'schedule_time' => $time,
            'retention_days' => max(1, min(90, (int) ($settings['retention_days'] ?? 3))),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function frequencyLabel(array $settings): string
    {
        $settings = self::normalize($settings);
        [$hour, $minute] = self::parseTime($settings['schedule_time']);
        $mm = str_pad((string) $minute, 2, '0', STR_PAD_LEFT);
        $hhmm = sprintf('%02d:%02d', $hour, $minute);

        return match ($settings['frequency']) {
            'hourly' => "Every hour at :{$mm}",
            'every_6_hours' => "Every 6 hours at :{$mm}",
            'every_12_hours' => "Every 12 hours at :{$mm}",
            default => "Daily at {$hhmm}",
        };
    }

    /** @return array{0: int, 1: int} */
    protected static function parseTime(string $time): array
    {
        $parts = explode(':', $time);
        $hour = max(0, min(23, (int) ($parts[0] ?? 2)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));

        return [$hour, $minute];
    }

    protected static function sourceLabel(): string
    {
        $org = self::platformOrganization();
        if (is_array($org?->module_settings[self::MODULE_KEY] ?? null)) {
            return 'platform';
        }

        return 'environment';
    }
}
