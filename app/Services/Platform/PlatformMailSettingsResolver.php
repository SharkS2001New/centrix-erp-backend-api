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
            'noreply_address' => '',
            'auth_mail_use_dedicated' => false,
            'auth_from_name' => '',
            'auth_from_address' => '',
            'auth_smtp_host' => '',
            'auth_smtp_port' => 587,
            'auth_smtp_username' => '',
            'auth_smtp_encryption' => 'tls',
            'auth_smtp_password_set' => false,
            'subscription_reminder_enabled' => false,
            'subscription_reminder_days' => '30,14,7',
            'renewal_email_subject' => 'Centrix ERP licence renewal reminder — {company_code}',
            'renewal_email_body' => "Dear {customer_name},\n\nYour Centrix ERP licence for {company_code} ({plan_name}) expires on {expires_on} ({days_remaining} day(s) remaining).\n\nPlease find attached invoice {invoice_number} for {total} to renew your subscription.\n\nIf you have already paid, you can ignore this message.\n\nRegards,\n{from_name}",
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
        $merged['auth_smtp_password_set'] = ! empty($stored['auth_smtp_password']);
        $merged['imap_extension_available'] = extension_loaded('imap');
        unset($merged['smtp_password'], $merged['imap_password'], $merged['auth_smtp_password']);

        return $merged;
    }

    /**
     * Effective settings for 2FA / email-verification mail.
     * Uses dedicated auth SMTP when enabled; otherwise falls back to main mail + noreply From.
     *
     * @return array<string, mixed>
     */
    public static function resolveForAuth(): array
    {
        $main = self::resolve();
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::SETTINGS_KEY] ?? null)
            ? $org->module_settings[self::SETTINGS_KEY]
            : [];

        if (! ($main['auth_mail_use_dedicated'] ?? false)) {
            $from = trim((string) ($main['noreply_address'] ?? ''));
            if ($from === '') {
                $base = trim((string) ($main['from_address'] ?? ''));
                $from = $base !== '' && str_contains($base, '@')
                    ? 'noreply@'.strtolower(explode('@', $base, 2)[1])
                    : $base;
            }

            return array_merge($main, [
                'auth_profile' => 'default',
                'from_name' => $main['from_name'] ?? 'Centrix',
                'from_address' => $from !== '' ? $from : ($main['from_address'] ?? ''),
                'reply_to' => '',
                'no_reply' => true,
            ]);
        }

        $fromName = trim((string) ($stored['auth_from_name'] ?? '')) ?: (string) ($main['from_name'] ?? 'Centrix');
        $fromAddress = trim((string) ($stored['auth_from_address'] ?? '')) ?: (string) ($main['noreply_address'] ?? $main['from_address'] ?? '');
        $host = trim((string) ($stored['auth_smtp_host'] ?? ''));
        $username = trim((string) ($stored['auth_smtp_username'] ?? ''));

        return array_merge($main, [
            'auth_profile' => 'auth',
            'enabled' => $host !== '',
            'from_name' => $fromName,
            'from_address' => $fromAddress,
            'reply_to' => '',
            'smtp_host' => $host !== '' ? $host : (string) ($main['smtp_host'] ?? ''),
            'smtp_port' => (int) ($stored['auth_smtp_port'] ?? $main['smtp_port'] ?? 587),
            'smtp_username' => $username !== '' ? $username : (string) ($main['smtp_username'] ?? ''),
            'smtp_encryption' => (string) ($stored['auth_smtp_encryption'] ?? $main['smtp_encryption'] ?? 'tls'),
            'smtp_password' => $stored['auth_smtp_password'] ?? $stored['smtp_password'] ?? null,
            'no_reply' => true,
        ]);
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
            'enabled', 'from_name', 'from_address', 'reply_to', 'noreply_address',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
            'imap_enabled', 'imap_host', 'imap_port', 'imap_username', 'imap_encryption', 'imap_mailbox',
            'contract_email_subject', 'contract_email_body',
            'auth_mail_use_dedicated', 'auth_from_name', 'auth_from_address',
            'auth_smtp_host', 'auth_smtp_port', 'auth_smtp_username', 'auth_smtp_encryption',
            'subscription_reminder_enabled', 'subscription_reminder_days',
            'renewal_email_subject', 'renewal_email_body',
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
        if (! empty($data['auth_smtp_password'])) {
            $current['auth_smtp_password'] = $data['auth_smtp_password'];
        }

        $settings[self::SETTINGS_KEY] = $current;
        $org->module_settings = $settings;
        $org->save();

        return self::resolve();
    }

    /**
     * @param  'default'|'auth'  $profile
     */
    public static function applyMailConfig(string $profile = 'default'): void
    {
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::SETTINGS_KEY] ?? null)
            ? $org->module_settings[self::SETTINGS_KEY]
            : [];
        $defaults = self::defaults();

        if ($profile === 'auth' && ! empty($stored['auth_mail_use_dedicated']) && ! empty($stored['auth_smtp_host'])) {
            $host = (string) $stored['auth_smtp_host'];
            $port = (int) ($stored['auth_smtp_port'] ?? 587);
            $encryption = (string) ($stored['auth_smtp_encryption'] ?? 'tls');
            $username = (string) ($stored['auth_smtp_username'] ?? '');
            $password = $stored['auth_smtp_password'] ?? null;
            $fromAddress = trim((string) ($stored['auth_from_address'] ?? ''))
                ?: (string) ($stored['from_address'] ?? $defaults['from_address']);
            $fromName = trim((string) ($stored['auth_from_name'] ?? ''))
                ?: (string) ($stored['from_name'] ?? $defaults['from_name']);
        } else {
            $settings = array_merge($defaults, $stored);
            $host = (string) ($settings['smtp_host'] ?? '');
            $port = (int) ($settings['smtp_port'] ?? 587);
            $encryption = (string) ($settings['smtp_encryption'] ?? 'tls');
            $username = (string) ($settings['smtp_username'] ?? '');
            $password = $stored['smtp_password'] ?? null;
            $fromAddress = (string) ($settings['from_address'] ?? '');
            $fromName = (string) ($settings['from_name'] ?? '');
        }

        if ($host === '') {
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption === 'none' ? null : $encryption,
            'username' => $username !== '' ? $username : null,
            'password' => $password,
            'timeout' => null,
        ]);
        Config::set('mail.from', [
            'address' => $fromAddress,
            'name' => $fromName,
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
