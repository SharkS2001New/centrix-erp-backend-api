<?php

namespace App\Services\SystemIssues;

use App\Models\Organization;

class SystemIssueAlertSettingsResolver
{
    public const MODULE_KEY = 'system_issue_alerts';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'email_digest_enabled' => true,
            'digest_email' => trim((string) config('system_issues.digest_email', '')),
            'instant_email_enabled' => false,
            'whatsapp_instant_enabled' => false,
            'whatsapp_number' => '',
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
    public static function forPlatform(): array
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
        $settings = self::forPlatform();

        return [
            'scope' => 'platform',
            'settings' => $settings,
            'effective' => [
                'digest_email' => self::digestEmail(),
                'whatsapp_number_e164' => self::whatsappNumberE164(),
                'email_digest_enabled' => (bool) $settings['email_digest_enabled'],
                'instant_email_enabled' => (bool) $settings['instant_email_enabled'],
                'whatsapp_instant_enabled' => (bool) $settings['whatsapp_instant_enabled'],
            ],
            'hints' => [
                'digest' => 'Daily email with the full open / acknowledged list.',
                'instant_whatsapp' => 'Instant WhatsApp for high-priority repeats, brand-new fingerprints, and user reports.',
                'instant_email' => 'Optional instant email for the same events as WhatsApp.',
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
        $current = self::forPlatform();
        $moduleSettings[self::MODULE_KEY] = self::normalize(array_merge($current, $incoming));
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(refresh: true);

        return self::describe();
    }

    public static function digestEmail(): string
    {
        $settings = self::forPlatform();
        $email = trim((string) ($settings['digest_email'] ?? ''));
        if ($email === '') {
            $email = trim((string) config('system_issues.digest_email', ''));
        }

        return $email;
    }

    public static function whatsappNumberE164(): ?string
    {
        $raw = trim((string) (self::forPlatform()['whatsapp_number'] ?? ''));
        if ($raw === '') {
            return null;
        }

        return \App\Support\PhoneNumber::toE164(
            $raw,
            (string) config('whatsapp.default_country_code', '254'),
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings): array
    {
        return [
            'email_digest_enabled' => (bool) ($settings['email_digest_enabled'] ?? true),
            'digest_email' => trim((string) ($settings['digest_email'] ?? '')),
            'instant_email_enabled' => (bool) ($settings['instant_email_enabled'] ?? false),
            'whatsapp_instant_enabled' => (bool) ($settings['whatsapp_instant_enabled'] ?? false),
            'whatsapp_number' => trim((string) ($settings['whatsapp_number'] ?? '')),
        ];
    }
}
