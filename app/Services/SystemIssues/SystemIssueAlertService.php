<?php

namespace App\Services\SystemIssues;

use App\Models\SystemIssueReport;
use App\Models\WhatsappConfig;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\ResolvedWhatsAppConfig;
use App\Services\WhatsApp\WhatsAppSettingsResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SystemIssueAlertService
{
    public function __construct(
        protected SystemIssueDigestService $digest,
        protected MetaWhatsAppClient $whatsapp,
    ) {}

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

        Mail::raw($plainBody, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        return true;
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
