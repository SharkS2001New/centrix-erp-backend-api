<?php

namespace App\Services\Notifications;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class NotificationSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.notifications', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $stored = is_array($organization->module_settings['notifications'] ?? null)
            ? $organization->module_settings['notifications']
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        return self::normalize(array_merge(
            self::defaults(),
            $gate->moduleSettings('notifications'),
        ));
    }

    /** @param  array<string, mixed>  $stored */
    /** @param  array<string, mixed>  $input */
    /** @return array{notifications: array<string, mixed>} */
    public static function mergeStored(array $stored, array $input): array
    {
        $next = array_merge($stored, array_filter(
            $input,
            fn ($key) => array_key_exists($key, self::defaults()),
            ARRAY_FILTER_USE_KEY,
        ));

        if (array_key_exists('africas_talking_api_key', $input)) {
            $key = trim((string) $input['africas_talking_api_key']);
            if ($key !== '' && ! str_starts_with($key, '••••')) {
                $next['africas_talking_api_key'] = $key;
            }
        }

        if (array_key_exists('smtp_password', $input)) {
            $password = trim((string) $input['smtp_password']);
            if ($password !== '' && ! str_starts_with($password, '••••')) {
                $next['smtp_password'] = $password;
            }
        }

        return ['notifications' => self::normalize($next)];
    }

    /** @param  array<string, mixed>  $settings */
    public static function maskForClient(array $settings): array
    {
        $out = self::normalize($settings);
        if (($out['africas_talking_api_key'] ?? '') !== '') {
            $out['africas_talking_api_key'] = '••••'.substr($out['africas_talking_api_key'], -4);
        }
        if (($out['smtp_password'] ?? '') !== '') {
            $out['smtp_password'] = '••••'.substr($out['smtp_password'], -4);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $settings */
    public static function mailFrom(Organization $organization, ?array $settings = null): array
    {
        $settings ??= self::forOrganization($organization);
        $name = trim((string) ($settings['email_from_name'] ?? ''));
        $address = trim((string) ($settings['email_from_address'] ?? ''));

        if ($name === '') {
            $name = trim((string) ($organization->org_name ?? ''));
        }
        if ($address === '') {
            $address = trim((string) ($organization->org_email ?? ''));
        }

        return [
            'name' => $name,
            'address' => $address,
        ];
    }

    /** @param  array<string, mixed>  $settings */
    public static function describe(array $settings, ?Organization $organization = null): array
    {
        $issues = [];
        if (! empty($settings['sms_enabled'])) {
            if (($settings['africas_talking_username'] ?? '') === '') {
                $issues[] = 'Africa\'s Talking username is required.';
            }
            if (($settings['africas_talking_api_key'] ?? '') === '') {
                $issues[] = 'Africa\'s Talking API key is required.';
            }
            if (($settings['africas_talking_sender_id'] ?? '') === '') {
                $issues[] = 'SMS sender ID is required.';
            }
        }

        $emailIssues = [];
        if (! empty($settings['email_enabled'])) {
            $from = $organization
                ? self::mailFrom($organization, $settings)
                : [
                    'name' => trim((string) ($settings['email_from_name'] ?? '')),
                    'address' => trim((string) ($settings['email_from_address'] ?? '')),
                ];

            if ($from['address'] === '' || ! filter_var($from['address'], FILTER_VALIDATE_EMAIL)) {
                $emailIssues[] = 'From email address is required (set below or on the company profile).';
            }
            if ($from['name'] === '') {
                $emailIssues[] = 'From name is recommended so recipients recognize your organization.';
            }

            if (! empty($settings['smtp_enabled'])) {
                if (trim((string) ($settings['smtp_host'] ?? '')) === '') {
                    $emailIssues[] = 'SMTP host is required when organization SMTP is enabled.';
                }
            }
        }

        $mailSender = app(OrganizationMailSender::class);
        $transportReady = $organization
            ? $mailSender->isTransportConfigured($organization)
            : $mailSender->isTransportConfigured();

        return [
            'sms_ready' => empty($issues) && ! empty($settings['sms_enabled']),
            'email_ready' => empty($emailIssues)
                && ! empty($settings['email_enabled'])
                && $transportReady,
            'mail_transport_configured' => $transportReady,
            'uses_organization_smtp' => $organization
                && ! empty($settings['smtp_enabled'])
                && trim((string) ($settings['smtp_host'] ?? '')) !== '',
            'issues' => $issues,
            'email_issues' => $emailIssues,
        ];
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $out = array_merge(self::defaults(), $settings);
        $out['sms_enabled'] = (bool) ($out['sms_enabled'] ?? false);
        $out['email_enabled'] = (bool) ($out['email_enabled'] ?? false);
        $out['smtp_enabled'] = (bool) ($out['smtp_enabled'] ?? false);
        $out['smtp_port'] = (int) ($out['smtp_port'] ?? 587);
        if ($out['smtp_port'] <= 0) {
            $out['smtp_port'] = 587;
        }
        $out['smtp_encryption'] = in_array($out['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true)
            ? $out['smtp_encryption']
            : 'tls';
        $out['notify_on_dispatch'] = (bool) ($out['notify_on_dispatch'] ?? false);
        $out['notify_on_delivery'] = (bool) ($out['notify_on_delivery'] ?? false);
        $out['notify_on_order_placed'] = (bool) ($out['notify_on_order_placed'] ?? false);
        $out['notify_on_debtor_payment'] = (bool) ($out['notify_on_debtor_payment'] ?? false);
        $out['notify_on_approval_request'] = (bool) ($out['notify_on_approval_request'] ?? false);
        $out['notify_on_approval_outcome'] = (bool) ($out['notify_on_approval_outcome'] ?? false);
        foreach (InAppNotificationEvents::organizationEvents() as $event) {
            $key = InAppNotificationEvents::settingKey($event);
            $out[$key] = (bool) ($out[$key] ?? self::defaults()[$key] ?? false);
        }
        $out['order_placed_scope'] = in_array($out['order_placed_scope'] ?? '', ['all', 'debtors', 'route_orders'], true)
            ? $out['order_placed_scope']
            : 'all';
        $out['debtor_payment_scope'] = in_array($out['debtor_payment_scope'] ?? '', ['all', 'debtors', 'route_orders'], true)
            ? $out['debtor_payment_scope']
            : 'debtors';
        $out['sms_provider'] = in_array($out['sms_provider'] ?? '', ['africas_talking'], true)
            ? $out['sms_provider']
            : 'africas_talking';

        foreach ([
            'africas_talking_username',
            'africas_talking_api_key',
            'africas_talking_sender_id',
            'email_from_name',
            'email_from_address',
            'smtp_host',
            'smtp_username',
            'smtp_password',
            'dispatch_sms_template',
            'delivery_sms_template',
            'dispatch_email_template',
            'delivery_email_template',
            'order_placed_sms_template',
            'order_placed_email_template',
            'debtor_payment_sms_template',
            'debtor_payment_email_template',
            'approval_request_email_subject',
            'approval_request_email_template',
            'approval_outcome_email_subject',
            'approval_outcome_email_template',
        ] as $key) {
            $out[$key] = trim((string) ($out[$key] ?? ''));
        }

        return $out;
    }

    /** @param  array<string, mixed>  $settings */
    public static function inAppEventEnabled(array $settings, string $event): bool
    {
        $key = InAppNotificationEvents::settingKey($event);

        return (bool) ($settings[$key] ?? self::defaults()[$key] ?? false);
    }

    public static function platformInAppEventEnabled(string $event): bool
    {
        $key = InAppNotificationEvents::settingKey($event);
        $defaults = config('erp.platform_notifications', []);

        return (bool) ($defaults[$key] ?? false);
    }

    public static function renderTemplate(string $template, array $vars): string
    {
        $message = $template;
        foreach ($vars as $key => $value) {
            $message = str_replace('{'.$key.'}', (string) $value, $message);
        }

        return $message;
    }
}
