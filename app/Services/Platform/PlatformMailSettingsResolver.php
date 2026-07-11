<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformMailMessage;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class PlatformMailSettingsResolver
{
    public const SETTINGS_KEY = 'platform_mail';

    /** @return list<string> */
    public static function accountFieldKeys(): array
    {
        return [
            'label', 'enabled', 'from_name', 'from_address', 'reply_to', 'noreply_address',
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'smtp_password',
            'imap_enabled', 'imap_host', 'imap_port', 'imap_username', 'imap_encryption',
            'imap_mailbox', 'imap_password',
        ];
    }

    public static function platformOrganization(): ?Organization
    {
        return Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();
    }

    /** @return array<string, mixed> */
    public static function accountDefaults(): array
    {
        return [
            'id' => '',
            'label' => 'Primary',
            'is_default' => true,
            'enabled' => false,
            'from_name' => 'ALPAC SOFTWARE SOLUTIONS',
            'from_address' => 'alpacke.tech@gmail.com',
            'reply_to' => 'alpacke.tech@gmail.com',
            'noreply_address' => '',
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
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_merge(self::accountDefaults(), [
            'imap_extension_available' => extension_loaded('imap'),
            'contract_email_subject' => 'Centrix ERP {kind}: {title}',
            'contract_email_body' => "Dear {customer_name},\n\nPlease find attached your Centrix ERP {kind} ({reference}).\n\nFirst payment: {first_payment}\nRenewal: {renewal_payment}\n\nIf you have questions, reply to this email.\n\nRegards,\n{from_name}",
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
            'renewal_invoice_design_id' => 'modern',
            'renewal_invoice_saved_template_id' => null,
            'accounts' => [],
            'active_account_id' => null,
        ]);
    }

    /** @return array<string, mixed> */
    public static function rawStored(): array
    {
        $org = self::platformOrganization();

        return is_array($org?->module_settings[self::SETTINGS_KEY] ?? null)
            ? $org->module_settings[self::SETTINGS_KEY]
            : [];
    }

    /**
     * Ensure accounts[] exists; migrate legacy flat SMTP/IMAP into the first account.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function ensureAccounts(array $stored): array
    {
        $accounts = $stored['accounts'] ?? null;
        if (! is_array($accounts) || $accounts === []) {
            $id = (string) Str::uuid();
            $account = self::accountDefaults();
            $account['id'] = $id;
            foreach (self::accountFieldKeys() as $key) {
                if ($key === 'label') {
                    continue;
                }
                if (array_key_exists($key, $stored)) {
                    $account[$key] = $stored[$key];
                }
            }
            $label = trim((string) ($account['from_address'] ?? ''));
            $account['label'] = $label !== '' ? $label : 'Primary';
            $account['is_default'] = true;
            $stored['accounts'] = [$account];
            $stored['active_account_id'] = $id;
        } else {
            $normalized = [];
            foreach ($accounts as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $account = array_merge(self::accountDefaults(), $row);
                if (trim((string) ($account['id'] ?? '')) === '') {
                    $account['id'] = (string) Str::uuid();
                }
                if (trim((string) ($account['label'] ?? '')) === '') {
                    $from = trim((string) ($account['from_address'] ?? ''));
                    $account['label'] = $from !== '' ? $from : ('Mailbox '.($index + 1));
                }
                $normalized[] = $account;
            }
            if ($normalized === []) {
                return self::ensureAccounts(array_diff_key($stored, ['accounts' => true]));
            }
            $hasDefault = false;
            foreach ($normalized as $i => $account) {
                if (! empty($account['is_default'])) {
                    if ($hasDefault) {
                        $normalized[$i]['is_default'] = false;
                    } else {
                        $hasDefault = true;
                    }
                }
            }
            if (! $hasDefault) {
                $normalized[0]['is_default'] = true;
            }
            $stored['accounts'] = $normalized;
            $active = (string) ($stored['active_account_id'] ?? '');
            $ids = array_column($normalized, 'id');
            if ($active === '' || ! in_array($active, $ids, true)) {
                $default = collect($normalized)->firstWhere('is_default', true) ?? $normalized[0];
                $stored['active_account_id'] = $default['id'];
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    public static function sanitizeAccount(array $account): array
    {
        $out = array_merge(self::accountDefaults(), $account);
        $out['smtp_password_set'] = ! empty($account['smtp_password']);
        $out['imap_password_set'] = ! empty($account['imap_password']);
        $out['smtp_port'] = (int) ($out['smtp_port'] ?? 587);
        $out['imap_port'] = (int) ($out['imap_port'] ?? 993);
        $out['enabled'] = (bool) ($out['enabled'] ?? false);
        $out['imap_enabled'] = (bool) ($out['imap_enabled'] ?? false);
        $out['is_default'] = (bool) ($out['is_default'] ?? false);
        unset($out['smtp_password'], $out['imap_password']);

        return $out;
    }

    /**
     * Suggest IMAP host/port/user from SMTP when IMAP fields are empty.
     *
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    public static function prefillImapFromSmtp(array $account): array
    {
        $smtpHost = strtolower(trim((string) ($account['smtp_host'] ?? '')));
        $smtpUser = trim((string) ($account['smtp_username'] ?? ''));
        $from = trim((string) ($account['from_address'] ?? ''));

        if (trim((string) ($account['imap_host'] ?? '')) === '' && $smtpHost !== '') {
            $imapHost = $smtpHost;
            if (str_starts_with($smtpHost, 'smtp.')) {
                $imapHost = 'imap.'.substr($smtpHost, 5);
            } elseif ($smtpHost === 'smtp.office365.com' || $smtpHost === 'smtp-mail.outlook.com') {
                $imapHost = 'outlook.office365.com';
            } elseif (str_contains($smtpHost, 'gmail.com') || str_contains($smtpHost, 'googlemail.com')) {
                $imapHost = 'imap.gmail.com';
            }
            $account['imap_host'] = $imapHost;
        }

        if (empty($account['imap_port'])) {
            $account['imap_port'] = 993;
        }
        if (trim((string) ($account['imap_encryption'] ?? '')) === '') {
            $account['imap_encryption'] = 'ssl';
        }
        if (trim((string) ($account['imap_mailbox'] ?? '')) === '') {
            $account['imap_mailbox'] = 'INBOX';
        }
        if (trim((string) ($account['imap_username'] ?? '')) === '') {
            $account['imap_username'] = $smtpUser !== '' ? $smtpUser : $from;
        }

        return $account;
    }

    /** @return array<string, mixed>|null */
    public static function findAccount(array $stored, ?string $accountId = null): ?array
    {
        $stored = self::ensureAccounts($stored);
        $accounts = $stored['accounts'];
        $id = $accountId !== null && $accountId !== ''
            ? $accountId
            : (string) ($stored['active_account_id'] ?? '');

        foreach ($accounts as $account) {
            if ((string) ($account['id'] ?? '') === $id) {
                return $account;
            }
        }

        foreach ($accounts as $account) {
            if (! empty($account['is_default'])) {
                return $account;
            }
        }

        return $accounts[0] ?? null;
    }

    /** @return array<string, mixed> */
    public static function resolve(?string $accountId = null): array
    {
        $defaults = self::defaults();
        $raw = self::rawStored();
        $needsPersist = ! is_array($raw['accounts'] ?? null) || $raw['accounts'] === [];
        $stored = self::ensureAccounts($raw);
        if ($needsPersist) {
            $org = self::platformOrganization();
            if ($org) {
                $settings = $org->module_settings ?? [];
                $settings[self::SETTINGS_KEY] = $stored;
                $org->module_settings = $settings;
                $org->save();
            }
        }
        $account = self::findAccount($stored, $accountId) ?? self::accountDefaults();
        $safeAccount = self::sanitizeAccount($account);

        $merged = array_merge($defaults, $stored, $safeAccount);
        $merged['accounts'] = array_map(
            fn ($row) => self::sanitizeAccount(is_array($row) ? $row : []),
            $stored['accounts'] ?? []
        );
        $merged['active_account_id'] = $stored['active_account_id'] ?? $safeAccount['id'];
        $merged['account_id'] = $safeAccount['id'];
        $merged['smtp_password_set'] = $safeAccount['smtp_password_set'];
        $merged['imap_password_set'] = $safeAccount['imap_password_set'];
        $merged['auth_smtp_password_set'] = ! empty($stored['auth_smtp_password']);
        $merged['imap_extension_available'] = extension_loaded('imap');
        unset(
            $merged['smtp_password'],
            $merged['imap_password'],
            $merged['auth_smtp_password'],
        );

        return $merged;
    }

    /**
     * Effective settings for 2FA / email-verification mail.
     *
     * @return array<string, mixed>
     */
    public static function resolveForAuth(): array
    {
        $main = self::resolve();
        $stored = self::ensureAccounts(self::rawStored());

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
            'smtp_password' => $stored['auth_smtp_password'] ?? null,
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
        $current = self::ensureAccounts(
            is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : []
        );

        foreach ([
            'contract_email_subject', 'contract_email_body',
            'auth_mail_use_dedicated', 'auth_from_name', 'auth_from_address',
            'auth_smtp_host', 'auth_smtp_port', 'auth_smtp_username', 'auth_smtp_encryption',
            'subscription_reminder_enabled', 'subscription_reminder_days',
            'renewal_email_subject', 'renewal_email_body',
            'renewal_invoice_design_id', 'renewal_invoice_saved_template_id',
            'active_account_id',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $current[$key] = $data[$key];
            }
        }
        if (! empty($data['auth_smtp_password'])) {
            $current['auth_smtp_password'] = $data['auth_smtp_password'];
        }

        if (! empty($data['add_account']) && is_array($data['add_account'])) {
            $new = array_merge(self::accountDefaults(), $data['add_account']);
            $new['id'] = (string) Str::uuid();
            $new['is_default'] = false;
            if (trim((string) ($new['label'] ?? '')) === '') {
                $from = trim((string) ($new['from_address'] ?? ''));
                $new['label'] = $from !== '' ? $from : ('Mailbox '.(count($current['accounts']) + 1));
            }
            if (! empty($data['prefill_imap_from_smtp'])) {
                $new = self::prefillImapFromSmtp($new);
            }
            $current['accounts'][] = $new;
            $current['active_account_id'] = $new['id'];
        }

        if (! empty($data['remove_account_id'])) {
            $removeId = (string) $data['remove_account_id'];
            $remaining = array_values(array_filter(
                $current['accounts'],
                fn ($row) => (string) ($row['id'] ?? '') !== $removeId
            ));
            if ($remaining === []) {
                abort(422, 'Keep at least one mailbox account.');
            }
            $hadDefault = collect($current['accounts'])->contains(
                fn ($row) => (string) ($row['id'] ?? '') === $removeId && ! empty($row['is_default'])
            );
            if ($hadDefault) {
                $remaining[0]['is_default'] = true;
            }
            $current['accounts'] = $remaining;
            if ((string) ($current['active_account_id'] ?? '') === $removeId) {
                $default = collect($remaining)->firstWhere('is_default', true) ?? $remaining[0];
                $current['active_account_id'] = $default['id'];
            }
        }

        if (! empty($data['set_default_account_id'])) {
            $defaultId = (string) $data['set_default_account_id'];
            foreach ($current['accounts'] as $i => $row) {
                $current['accounts'][$i]['is_default'] = (string) ($row['id'] ?? '') === $defaultId;
            }
        }

        $editId = (string) ($data['account_id'] ?? $current['active_account_id'] ?? '');
        $accountIndex = null;
        foreach ($current['accounts'] as $i => $row) {
            if ((string) ($row['id'] ?? '') === $editId) {
                $accountIndex = $i;
                break;
            }
        }
        if ($accountIndex === null && isset($current['accounts'][0])) {
            $accountIndex = 0;
            $editId = (string) $current['accounts'][0]['id'];
        }

        if ($accountIndex !== null) {
            $account = $current['accounts'][$accountIndex];
            foreach ([
                'label', 'enabled', 'from_name', 'from_address', 'reply_to', 'noreply_address',
                'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
                'imap_enabled', 'imap_host', 'imap_port', 'imap_username', 'imap_encryption', 'imap_mailbox',
            ] as $key) {
                if (array_key_exists($key, $data)) {
                    $account[$key] = $data[$key];
                }
            }
            if (! empty($data['smtp_password'])) {
                $account['smtp_password'] = $data['smtp_password'];
            }
            if (! empty($data['imap_password'])) {
                $account['imap_password'] = $data['imap_password'];
            }
            if (! empty($data['prefill_imap_from_smtp'])) {
                $account = self::prefillImapFromSmtp($account);
            }
            if (trim((string) ($account['label'] ?? '')) === '') {
                $from = trim((string) ($account['from_address'] ?? ''));
                $account['label'] = $from !== '' ? $from : 'Mailbox';
            }
            $current['accounts'][$accountIndex] = $account;
            $current['active_account_id'] = $editId;

            $defaultAccount = collect($current['accounts'])->firstWhere('is_default', true)
                ?? $current['accounts'][0];
            foreach (self::accountFieldKeys() as $key) {
                if ($key === 'label') {
                    continue;
                }
                if (array_key_exists($key, $defaultAccount)) {
                    $current[$key] = $defaultAccount[$key];
                }
            }
        }

        $settings[self::SETTINGS_KEY] = $current;
        $org->module_settings = $settings;
        $org->save();

        return self::resolve($editId !== '' ? $editId : null);
    }

    /** @return list<array<string, mixed>> */
    public static function listComposeTemplates(): array
    {
        $stored = self::rawStored();
        $templates = $stored['compose_templates'] ?? [];
        if (! is_array($templates)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($row) {
            if (! is_array($row)) {
                return null;
            }

            return [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? 'Untitled'),
                'subject' => (string) ($row['subject'] ?? ''),
                'body' => (string) ($row['body'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $templates)));
    }

    /**
     * @param  array{name: string, subject: string, body: string, id?: string}  $data
     * @return list<array<string, mixed>>
     */
    public static function saveComposeTemplate(array $data): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(422, 'PLATFORM organization not found.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = (string) ($data['body'] ?? '');
        if ($name === '') {
            abort(422, 'Enter a template name.');
        }
        if ($subject === '' && trim($body) === '') {
            abort(422, 'Template needs a subject or body.');
        }

        $settings = $org->module_settings ?? [];
        $current = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];
        $templates = is_array($current['compose_templates'] ?? null) ? $current['compose_templates'] : [];
        $id = trim((string) ($data['id'] ?? ''));
        $now = now()->toIso8601String();

        if ($id !== '') {
            $found = false;
            foreach ($templates as $i => $row) {
                if (! is_array($row) || (string) ($row['id'] ?? '') !== $id) {
                    continue;
                }
                $templates[$i] = array_merge($row, [
                    'name' => $name,
                    'subject' => $subject,
                    'body' => $body,
                    'updated_at' => $now,
                ]);
                $found = true;
                break;
            }
            if (! $found) {
                abort(404, 'Email template not found.');
            }
        } else {
            $templates[] = [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'subject' => $subject,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        usort($templates, fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        $current['compose_templates'] = array_values($templates);
        $settings[self::SETTINGS_KEY] = $current;
        $org->module_settings = $settings;
        $org->save();

        return self::listComposeTemplates();
    }

    /** @return list<array<string, mixed>> */
    public static function deleteComposeTemplate(string $id): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(422, 'PLATFORM organization not found.');
        }

        $settings = $org->module_settings ?? [];
        $current = is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [];
        $templates = is_array($current['compose_templates'] ?? null) ? $current['compose_templates'] : [];
        $before = count($templates);
        $templates = array_values(array_filter(
            $templates,
            fn ($row) => ! is_array($row) || (string) ($row['id'] ?? '') !== $id
        ));
        if (count($templates) === $before) {
            abort(404, 'Email template not found.');
        }

        $current['compose_templates'] = $templates;
        $settings[self::SETTINGS_KEY] = $current;
        $org->module_settings = $settings;
        $org->save();

        return self::listComposeTemplates();
    }

    /**
     * @param  'default'|'auth'  $profile
     */
    public static function applyMailConfig(string $profile = 'default', ?string $accountId = null): void
    {
        $stored = self::ensureAccounts(self::rawStored());
        $defaults = self::accountDefaults();

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
            $account = self::findAccount($stored, $accountId) ?? $defaults;
            $host = (string) ($account['smtp_host'] ?? '');
            $port = (int) ($account['smtp_port'] ?? 587);
            $encryption = (string) ($account['smtp_encryption'] ?? 'tls');
            $username = (string) ($account['smtp_username'] ?? '');
            $password = $account['smtp_password'] ?? null;
            $fromAddress = (string) ($account['from_address'] ?? '');
            $fromName = (string) ($account['from_name'] ?? '');
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
