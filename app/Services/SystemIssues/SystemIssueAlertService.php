<?php

namespace App\Services\SystemIssues;

use App\Models\SystemIssueReport;
use App\Models\WhatsappConfig;
use App\Services\Platform\PlatformMailSettingsResolver;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\ResolvedWhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemIssueAlertService
{
    public function __construct(
        protected SystemIssueDigestService $digest,
        protected MetaWhatsAppClient $whatsapp,
    ) {}

    /**
     * Send a test system-error alert so platform admins can verify email / WhatsApp delivery.
     *
     * @param  list<string>  $channels  email, whatsapp
     * @return array{ok: bool, channels: array<string, array{ok: bool, message: string}>, from_address: string, to_email: string}
     */
    public function sendTest(array $channels = ['email']): array
    {
        $channels = array_values(array_unique(array_filter($channels)));
        if ($channels === []) {
            $channels = ['email'];
        }

        $delivery = SystemIssueAlertSettingsResolver::deliverySnapshot();
        $results = [];
        $body = "Centrix alert [TEST]\nThis is a test of System errors & reports notifications.\n"
            ."If you received this, delivery is working.\n"
            ."From: ".($delivery['from_address'] ?: '(not configured)')."\n"
            ."Platform → Settings → Alert notifications";

        if (in_array('email', $channels, true)) {
            $results['email'] = $this->sendTestEmail($body, $delivery);
        }
        if (in_array('whatsapp', $channels, true)) {
            $results['whatsapp'] = $this->sendTestWhatsApp($body);
        }

        $ok = $results !== [] && collect($results)->every(fn ($row) => (bool) ($row['ok'] ?? false));

        return [
            'ok' => $ok,
            'channels' => $results,
            'from_address' => $delivery['from_address'],
            'from_name' => $delivery['from_name'],
            'to_email' => $delivery['to_email'],
        ];
    }

    public function sendInstantIfNeeded(SystemIssueReport $report): void
    {
        try {
            if (! $this->shouldSendInstant($report)) {
                return;
            }

            $settings = SystemIssueAlertSettingsResolver::forPlatform();
            $message = $this->buildInstantMessage($report);

            if (! empty($settings['whatsapp_instant_enabled'])) {
                $this->sendWhatsApp($message);
            }

            if (! empty($settings['instant_email_enabled'])) {
                $this->sendInstantEmail($report, $message);
            }
        } catch (Throwable $e) {
            Log::warning('system_issue.instant_alert_failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function shouldSendInstant(SystemIssueReport $report): bool
    {
        $settings = SystemIssueAlertSettingsResolver::forPlatform();
        if (empty($settings['whatsapp_instant_enabled']) && empty($settings['instant_email_enabled'])) {
            return false;
        }

        if (($report->kind ?? '') === 'user_report' || (bool) ($report->reported_by_user ?? false)) {
            return true;
        }

        $fingerprint = trim((string) ($report->fingerprint ?? ''));
        if ($fingerprint === '') {
            return ($report->kind ?? '') === 'error';
        }

        $count = $this->digest->occurrenceCountForFingerprint($fingerprint);
        if ($count <= 1) {
            return true; // brand-new fingerprint
        }

        return $count >= $this->digest->repeatThreshold(); // high priority
    }

    protected function buildInstantMessage(SystemIssueReport $report): string
    {
        $report->loadMissing(['organization:id,org_name,company_code', 'user:id,username,full_name']);

        $kind = strtoupper((string) $report->kind);
        $org = $report->organization?->company_code
            ?? $report->organization?->org_name
            ?? '—';
        $user = $report->user?->full_name ?: ($report->user?->username ?: '—');
        $api = $report->api_path ? "\nAPI: {$report->api_path}" : '';
        $priority = '';
        if ($report->fingerprint) {
            $count = $this->digest->occurrenceCountForFingerprint($report->fingerprint);
            if ($count >= $this->digest->repeatThreshold()) {
                $priority = " [HIGH ×{$count}]";
            } elseif ($count <= 1) {
                $priority = ' [NEW]';
            }
        }
        if (($report->kind ?? '') === 'user_report' || (bool) ($report->reported_by_user ?? false)) {
            $priority = $priority !== '' ? $priority.' [USER]' : ' [USER]';
        }

        $message = mb_substr((string) $report->message, 0, 280);

        return "Centrix alert{$priority}\n{$kind} · {$org} · {$user}\n{$message}{$api}\nPlatform → System errors & reports";
    }

    /** @return array{ok: bool, message: string} */
    protected function sendTestEmail(string $plainBody, array $delivery): array
    {
        $to = SystemIssueAlertSettingsResolver::digestEmail();
        if ($to === '') {
            return [
                'ok' => false,
                'message' => 'No digest email is set. Save a recipient under Alert notifications first.',
            ];
        }
        if (! ($delivery['ready'] ?? false) || ($delivery['from_address'] ?? '') === '') {
            return [
                'ok' => false,
                'message' => 'Notification SMTP is not ready. Set From + SMTP under Email delivery → Notifications.',
            ];
        }

        try {
            PlatformMailSettingsResolver::sendRaw(
                $to,
                '[Centrix] TEST — System errors & reports',
                $plainBody,
                null,
                ['kind' => 'system_issue_alert', 'no_reply' => true, 'purpose' => 'test'],
            );

            return [
                'ok' => true,
                'message' => "Sent from {$delivery['from_address']} to {$to}.",
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /** @return array{ok: bool, message: string} */
    protected function sendTestWhatsApp(string $message): array
    {
        $to = SystemIssueAlertSettingsResolver::whatsappNumberE164();
        if (! $to) {
            return ['ok' => false, 'message' => 'No WhatsApp number is set.'];
        }
        $config = $this->resolveWhatsAppConfig();
        if (! $config) {
            return ['ok' => false, 'message' => 'WhatsApp Cloud API credentials are missing.'];
        }

        try {
            $this->whatsapp->sendText($config, $to, $message);

            return ['ok' => true, 'message' => "Sent WhatsApp test to {$to}."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    protected function sendWhatsApp(string $message): bool
    {
        $to = SystemIssueAlertSettingsResolver::whatsappNumberE164();
        if (! $to) {
            Log::info('system_issue.whatsapp_skipped', ['reason' => 'no_recipient']);

            return false;
        }

        $config = $this->resolveWhatsAppConfig();
        if (! $config) {
            Log::warning('system_issue.whatsapp_skipped', ['reason' => 'no_whatsapp_credentials']);

            return false;
        }

        return $this->whatsapp->sendText($config, $to, $message);
    }

    protected function sendInstantEmail(SystemIssueReport $report, string $plainBody): bool
    {
        $to = SystemIssueAlertSettingsResolver::digestEmail();
        if ($to === '') {
            return false;
        }

        $subject = sprintf(
            '[Centrix] %s — %s',
            strtoupper((string) $report->kind),
            mb_substr((string) $report->message, 0, 80),
        );

        if (! $this->platformMailFromAddress()) {
            Log::warning('system_issue.instant_email_skipped', [
                'reason' => 'missing_from_address',
                'report_id' => $report->id,
                'hint' => 'Set notification SMTP under Platform → Settings → Email delivery → Notifications.',
            ]);

            return false;
        }

        PlatformMailSettingsResolver::sendRaw($to, $subject, $plainBody, null, [
            'kind' => 'system_issue_alert',
            'no_reply' => true,
            'organization_id' => $report->organization_id,
        ]);

        return true;
    }

    protected function platformMailFromAddress(): string
    {
        $settings = PlatformMailSettingsResolver::resolveForAuth();
        $from = trim((string) ($settings['from_address'] ?? ''));
        if ($from === '') {
            $from = trim((string) config('mail.from.address', ''));
        }

        return filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : '';
    }

    protected function resolveWhatsAppConfig(): ?ResolvedWhatsAppConfig
    {
        $row = WhatsappConfig::query()
            ->where('is_active', true)
            ->whereNotNull('phone_number_id')
            ->where('phone_number_id', '!=', '')
            ->whereNotNull('access_token')
            ->where('access_token', '!=', '')
            ->orderBy('id')
            ->first();

        if ($row) {
            return ResolvedWhatsAppConfig::fromModel($row);
        }

        $token = trim((string) config('whatsapp.access_token', ''));
        $phoneNumberId = trim((string) config('whatsapp.phone_number_id', ''));
        if ($token === '' || $phoneNumberId === '') {
            return null;
        }

        $org = WhatsAppSettingsResolver::platformOrganization();

        return new ResolvedWhatsAppConfig(
            organizationId: (int) ($org?->id ?? 0),
            branchId: null,
            botUserId: (int) config('whatsapp.bot_user_id', 0),
            phoneNumberId: $phoneNumberId,
            accessToken: $token,
            webhookVerifyToken: WhatsAppSettingsResolver::platformVerifyToken(),
            graphApiVersion: (string) config('whatsapp.graph_api_version', 'v21.0'),
        );
    }
}
