<?php

namespace App\Services\Notifications;

use App\Models\Organization;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrganizationMailSender
{
    public function isTransportConfigured(?Organization $organization = null): bool
    {
        if ($organization && $this->hasOrganizationSmtp($organization)) {
            return true;
        }

        return $this->isServerTransportConfigured();
    }

    /**
     * @return array{name: string, address: string}
     */
    public function resolveFrom(Organization $organization, ?array $settings = null): array
    {
        $from = NotificationSettingsResolver::mailFrom($organization, $settings);

        if ($from['address'] !== '') {
            return $from;
        }

        $envAddress = trim((string) config('mail.from.address', ''));
        $envName = trim((string) config('mail.from.name', ''));

        return [
            'name' => $envName !== '' ? $envName : trim((string) ($organization->org_name ?? '')),
            'address' => $envAddress,
        ];
    }

    public function canSendForOrganization(
        Organization $organization,
        bool $requireNotificationsEnabled = false,
    ): bool {
        if (! $this->isTransportConfigured($organization)) {
            return false;
        }

        $settings = NotificationSettingsResolver::forOrganization($organization);
        if ($requireNotificationsEnabled && empty($settings['email_enabled'])) {
            return false;
        }

        $from = $this->resolveFrom($organization, $settings);

        return filter_var($from['address'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public function sendRaw(
        Organization $organization,
        string $to,
        string $subject,
        string $body,
        bool $requireNotificationsEnabled = false,
    ): bool {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (! $this->canSendForOrganization($organization, $requireNotificationsEnabled)) {
            return false;
        }

        $from = $this->resolveFrom($organization);
        $mailer = $this->configureMailer($organization);

        try {
            Mail::mailer($mailer)->raw($body, function ($message) use ($to, $subject, $from) {
                $message->to($to)->subject($subject);
                $message->from(
                    $from['address'],
                    $from['name'] !== '' ? $from['name'] : null,
                );
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('Organization email could not be sent', [
                'organization_id' => $organization->id,
                'organization' => $organization->company_code,
                'mailer' => $mailer,
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function isServerTransportConfigured(): bool
    {
        $default = (string) config('mail.default', 'smtp');

        if (in_array($default, ['log', 'array', 'failover', 'sendmail'], true)) {
            return true;
        }

        if ($default === 'smtp') {
            return (string) config('mail.mailers.smtp.host', '') !== '';
        }

        return true;
    }

    protected function hasOrganizationSmtp(Organization $organization): bool
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);

        return ! empty($settings['smtp_enabled'])
            && trim((string) ($settings['smtp_host'] ?? '')) !== '';
    }

    protected function configureMailer(Organization $organization): string
    {
        if (! $this->hasOrganizationSmtp($organization)) {
            return (string) config('mail.default', 'smtp');
        }

        $settings = NotificationSettingsResolver::forOrganization($organization);
        $mailerName = 'organization_'.$organization->id;

        Config::set("mail.mailers.{$mailerName}", [
            'transport' => 'smtp',
            'host' => $settings['smtp_host'],
            'port' => (int) ($settings['smtp_port'] ?? 587),
            'encryption' => ($settings['smtp_encryption'] ?? 'tls') === 'none'
                ? null
                : ($settings['smtp_encryption'] ?? 'tls'),
            'username' => $settings['smtp_username'] ?? null,
            'password' => $settings['smtp_password'] ?? null,
            'timeout' => null,
            'local_domain' => parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST),
        ]);

        return $mailerName;
    }
}
