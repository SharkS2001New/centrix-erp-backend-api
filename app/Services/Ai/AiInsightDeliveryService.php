<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Services\Notifications\AfricasTalkingSmsService;
use App\Services\Notifications\OrganizationMailSender;
use App\Services\WhatsApp\MetaWhatsAppClient;
use App\Services\WhatsApp\WhatsAppConfigResolver;
use Illuminate\Support\Facades\Log;

class AiInsightDeliveryService
{
    public function __construct(
        protected OrganizationMailSender $mail,
        protected AfricasTalkingSmsService $sms,
        protected MetaWhatsAppClient $whatsapp,
        protected WhatsAppConfigResolver $whatsappConfigs,
    ) {}

    /**
     * @param  array<string, mixed>  $insight
     * @param  array<string, mixed>|null  $overrideRecipients
     * @return array{sent: array<string, int>, skipped: list<string>, errors: list<string>}
     */
    public function deliver(Organization $organization, array $insight, ?array $overrideRecipients = null): array
    {
        $settings = AiSettingsResolver::insightsForOrganization($organization);
        $channels = $settings['channels'] ?? [];
        $recipients = $overrideRecipients ?? ($settings['recipients'] ?? []);

        $subject = $this->subjectFor($insight);
        $body = $this->bodyFor($insight);
        $short = $this->smsBodyFor($insight);

        $sent = ['email' => 0, 'whatsapp' => 0, 'sms' => 0];
        $skipped = [];
        $errors = [];

        if (! empty($channels['email'])) {
            $emails = $this->stringList($recipients['emails'] ?? []);
            if ($emails === []) {
                $skipped[] = 'email: no recipients';
            } else {
                foreach ($emails as $email) {
                    try {
                        $ok = $this->mail->sendRaw($organization, $email, $subject, $body);
                        if ($ok) {
                            $sent['email']++;
                        } else {
                            $errors[] = "email failed: {$email}";
                        }
                    } catch (\Throwable $e) {
                        Log::warning('AI insight email failed', ['email' => $email, 'message' => $e->getMessage()]);
                        $errors[] = "email error: {$email}";
                    }
                }
            }
        } else {
            $skipped[] = 'email disabled';
        }

        if (! empty($channels['whatsapp'])) {
            $phones = $this->stringList($recipients['whatsapp_phones'] ?? []);
            $config = $this->whatsappConfigs->resolveForOrganizationPreview($organization);
            if (! $config) {
                $skipped[] = 'whatsapp: not configured';
            } elseif ($phones === []) {
                $skipped[] = 'whatsapp: no recipients';
            } else {
                foreach ($phones as $phone) {
                    try {
                        if ($this->whatsapp->sendText($config, $phone, $short)) {
                            $sent['whatsapp']++;
                        } else {
                            $errors[] = "whatsapp failed: {$phone}";
                        }
                    } catch (\Throwable $e) {
                        Log::warning('AI insight WhatsApp failed', ['phone' => $phone, 'message' => $e->getMessage()]);
                        $errors[] = "whatsapp error: {$phone}";
                    }
                }
            }
        } else {
            $skipped[] = 'whatsapp disabled';
        }

        if (! empty($channels['sms'])) {
            $phones = $this->stringList($recipients['phones'] ?? []);
            if ($phones === []) {
                $skipped[] = 'sms: no recipients';
            } else {
                foreach ($phones as $phone) {
                    try {
                        if ($this->sms->send($organization, $phone, $short)) {
                            $sent['sms']++;
                        } else {
                            $errors[] = "sms failed: {$phone}";
                        }
                    } catch (\Throwable $e) {
                        Log::warning('AI insight SMS failed', ['phone' => $phone, 'message' => $e->getMessage()]);
                        $errors[] = "sms error: {$phone}";
                    }
                }
            }
        } else {
            $skipped[] = 'sms disabled';
        }

        return compact('sent', 'skipped', 'errors');
    }

    /** @param  array<string, mixed>  $insight */
    protected function subjectFor(array $insight): string
    {
        $type = (string) ($insight['type'] ?? 'insight');
        $label = match ($type) {
            'stock_pulse' => 'Stock Pulse',
            'sales_brief' => 'Sales Brief',
            'report_analyze' => 'Report Analysis',
            default => 'AI Insight',
        };

        return "Centrix {$label}";
    }

    /** @param  array<string, mixed>  $insight */
    protected function bodyFor(array $insight): string
    {
        $lines = [
            (string) ($insight['summary'] ?? ''),
            '',
            'Findings:',
        ];
        foreach ($insight['findings'] ?? [] as $finding) {
            $lines[] = '- '.$finding;
        }
        if (! empty($insight['actions'])) {
            $lines[] = '';
            $lines[] = 'Suggested actions:';
            foreach ($insight['actions'] as $action) {
                $label = is_array($action) ? ($action['label'] ?? '') : (string) $action;
                $href = is_array($action) ? ($action['href'] ?? '') : '';
                $lines[] = $href ? "- {$label} ({$href})" : "- {$label}";
            }
        }
        $lines[] = '';
        $lines[] = '— Centrix AI Insights';

        return trim(implode("\n", $lines));
    }

    /** @param  array<string, mixed>  $insight */
    protected function smsBodyFor(array $insight): string
    {
        $summary = trim((string) ($insight['summary'] ?? ''));
        if (strlen($summary) > 400) {
            $summary = substr($summary, 0, 397).'…';
        }
        $type = (string) ($insight['type'] ?? 'insight');

        return "Centrix {$type}: {$summary}";
    }

    /** @param  mixed  $value
     * @return list<string>
     */
    protected function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string) $v),
            $value,
        ))));
    }
}
