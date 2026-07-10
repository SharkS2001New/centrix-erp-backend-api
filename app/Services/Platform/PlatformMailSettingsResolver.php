<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformMailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Config;

class PlatformMailSettingsResolver
{
    public const SETTINGS_KEY = 'platform_mail';

    public static function platformOrganization(): ?Organization
    {
        return Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'from_name' => 'ALPAC SOFTWARE SOLUTIONS',
            'from_address' => 'alpacke.tech@gmail.com',
            'reply_to' => 'alpacke.tech@gmail.com',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_encryption' => 'tls',
            'smtp_password_set' => false,
            'imap_enabled' => false,
            'imap_host' => '',
            'imap_port' => 993,
            'imap_username' => '',
            'imap_encryption' => 'ssl',
            'imap_mailbox' => 'INBOX',
            'imap_password_set' => false,
            'imap_extension_available' => extension_loaded('imap'),
            'contract_email_subject' => 'Centrix ERP {kind}: {title}',
            'contract_email_body' => "Dear {customer_name},\n\nPlease find attached your Centrix ERP {kind} ({reference}).\n\nFirst payment: {first_payment}\nRenewal: {renewal_payment}\n\nIf you have questions, reply to this email.\n\nRegards,\n{from_name}",
        ];
    }

    /** @return array<string, mixed> */
    public static function resolve(): array
    {
        $defaults = self::defaults();
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::SETTINGS_KEY] ?? null)
            ? $org->module_settings[self::SETTINGS_KEY]
            : [];

        $merged = array_merge($defaults, $stored);
        $merged['smtp_password_set'] = ! empty($stored['smtp_password']);
        $merged['imap_password_set'] = ! empty($stored['imap_password']);
        $merged['imap_extension_available'] = extension_loaded('imap');
        unset($merged['smtp_password'], $merged['imap_password']);

        return $merged;
    }

    /** @param  array<string, mixed>  $data */
    public static function save(array $data): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(422, 'PLATFORM organization not found.');
        }

        $settings = $org->module_settings ?? [];
        $current = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];

        foreach ([
            'enabled', 'from_name', 'from_address', 'reply_to',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
            'imap_enabled', 'imap_host', 'imap_port', 'imap_username', 'imap_encryption', 'imap_mailbox',
            'contract_email_subject', 'contract_email_body',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $current[$key] = $data[$key];
            }
        }
        if (! empty($data['smtp_password'])) {
            $current['smtp_password'] = $data['smtp_password'];
        }
        if (! empty($data['imap_password'])) {
            $current['imap_password'] = $data['imap_password'];
        }

        $settings[self::SETTINGS_KEY] = $current;
        $org->module_settings = $settings;
        $org->save();

        return self::resolve();
    }

    public static function applyMailConfig(): void
    {
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::SETTINGS_KEY] ?? null)
            ? $org->module_settings[self::SETTINGS_KEY]
            : [];
        $settings = array_merge(self::defaults(), $stored);

        if (empty($settings['smtp_host'])) {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $settings['smtp_host'],
            'port' => (int) ($settings['smtp_port'] ?? 587),
            'encryption' => ($settings['smtp_encryption'] ?? 'tls') === 'none' ? null : ($settings['smtp_encryption'] ?? 'tls'),
            'username' => $settings['smtp_username'] ?: null,
            'password' => $stored['smtp_password'] ?? null,
            'timeout' => null,
        ]);
        Config::set('mail.from', [
            'address' => $settings['from_address'],
            'name' => $settings['from_name'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function sendRaw(string $to, string $subject, string $body, ?User $user = null, array $meta = []): PlatformMailMessage
    {
        return app(PlatformMailboxService::class)->send($to, $subject, $body, $user, $meta);
    }
}
