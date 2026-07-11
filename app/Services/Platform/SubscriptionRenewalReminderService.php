<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformInvoice;
use App\Models\PlatformSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SubscriptionRenewalReminderService
{
    public function __construct(
        protected PlatformInvoiceBillingService $billing,
        protected PlatformInvoiceDocumentService $documents,
        protected PlatformMailboxService $mailbox,
    ) {}

    /**
     * @return array{sent: int, skipped: int, errors: list<string>}
     */
    public function processDueReminders(?Carbon $today = null): array
    {
        $settings = PlatformMailSettingsResolver::resolve();
        if (! ($settings['subscription_reminder_enabled'] ?? false)) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => ['Subscription renewal reminders are disabled.']];
        }

        if (! ($settings['enabled'] ?? false)) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => ['Platform email delivery is disabled.']];
        }

        $daysList = $this->reminderDays($settings);
        if ($daysList === []) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => ['No reminder day offsets configured.']];
        }

        $today = ($today ?? Carbon::today())->startOfDay();
        $sent = 0;
        $skipped = 0;
        $errors = [];

        $subs = PlatformSubscription::query()
            ->with(['organization', 'plan'])
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->get();

        foreach ($subs as $sub) {
            $expiresAt = $this->expiresAt($sub);
            if (! $expiresAt) {
                $skipped++;
                continue;
            }

            $daysRemaining = (int) $today->diffInDays($expiresAt->copy()->startOfDay(), false);
            if (! in_array($daysRemaining, $daysList, true)) {
                continue;
            }

            $log = is_array($sub->reminder_log) ? $sub->reminder_log : [];
            $periodKey = $expiresAt->toDateString();
            $already = is_array($log[$periodKey] ?? null) ? $log[$periodKey] : [];
            if (in_array($daysRemaining, array_map('intval', $already), true)) {
                $skipped++;
                continue;
            }

            try {
                $this->sendReminder($sub, $daysRemaining, $expiresAt, $settings);
                $already[] = $daysRemaining;
                $log[$periodKey] = array_values(array_unique(array_map('intval', $already)));
                $sub->forceFill(['reminder_log' => $log])->save();
                $sent++;
            } catch (\Throwable $e) {
                $errors[] = "Subscription #{$sub->id}: ".$e->getMessage();
                Log::warning('Subscription renewal reminder failed', [
                    'subscription_id' => $sub->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('sent', 'skipped', 'errors');
    }

    /**
     * Create (or reuse) a draft renewal invoice for the subscription.
     */
    public function createDraftRenewalInvoice(PlatformSubscription $sub): PlatformInvoice
    {
        $sub->loadMissing(['organization', 'plan']);
        $org = $sub->organization;
        if (! $org) {
            throw new \RuntimeException('Subscription has no organization.');
        }

        $expiresAt = $this->expiresAt($sub);
        $amount = (float) ($sub->renewal_price
            ?? $sub->amount
            ?? $sub->plan?->renewal_price
            ?? $sub->plan?->price
            ?? 0);
        $currency = (string) ($sub->currency ?: $sub->plan?->currency ?: 'KES');
        $planName = (string) ($sub->plan?->name ?: 'Centrix ERP plan');
        $periodLabel = $expiresAt
            ? 'period ending '.$expiresAt->toDateString()
            : 'upcoming renewal';

        // Reuse an unpaid draft already linked to this subscription.
        if ($sub->invoice_id) {
            $existing = PlatformInvoice::query()->find($sub->invoice_id);
            if ($existing && in_array($existing->status, ['draft', 'sent'], true)
                && (float) $existing->total > 0
                && abs((float) $existing->total - $amount) < 0.01) {
                return $existing;
            }
        }

        $context = $this->billing->billingContext($org);
        $billTo = $context['bill_to'];
        $adminEmail = $this->primaryRecipientEmail($org);
        if ($adminEmail && empty($billTo['email'])) {
            $billTo['email'] = $adminEmail;
        }

        $lineItems = [[
            'module_key' => null,
            'description' => "Centrix ERP renewal — {$planName} ({$periodLabel})",
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
            'included' => true,
        ]];
        $taxRate = 0.0;
        $totals = $this->billing->calculateTotals($lineItems, $taxRate);

        $invoice = PlatformInvoice::query()->create([
            'invoice_number' => $this->billing->nextInvoiceNumber(),
            'organization_id' => $org->id,
            'status' => 'draft',
            'template_id' => 'modern',
            'currency' => $currency,
            'issue_date' => now()->toDateString(),
            'due_date' => $expiresAt?->toDateString(),
            'bill_to_name' => $billTo['name'] ?? $org->org_name,
            'bill_to_email' => $billTo['email'] ?? $adminEmail,
            'bill_to_phone' => $billTo['phone'] ?? $org->primary_tel,
            'bill_to_address' => $billTo['address'] ?? $org->org_address,
            'bill_to_tax_pin' => $billTo['tax_pin'] ?? $org->org_pin,
            'bill_to_company_code' => $billTo['company_code'] ?? $org->company_code,
            'seller' => $context['seller'] ?? $this->billing->platformSeller(),
            'invoice_options' => [
                'show_branding' => true,
                'show_quantity' => true,
                'show_payment_details' => true,
            ],
            'line_items' => $lineItems,
            'selected_modules' => $sub->module_keys ?? [],
            'subtotal' => $totals['subtotal'],
            'tax_rate' => $taxRate,
            'tax_amount' => $totals['tax_amount'],
            'total' => $totals['total'],
            'notes' => 'Auto-generated renewal invoice for subscription reminder.',
            'terms' => 'Payment renews your Centrix ERP licence for the next billing period.',
        ]);

        $sub->forceFill(['invoice_id' => $invoice->id])->save();

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function sendReminder(
        PlatformSubscription $sub,
        int $daysRemaining,
        Carbon $expiresAt,
        array $settings,
    ): void {
        $org = $sub->organization;
        if (! $org) {
            throw new \RuntimeException('Missing organization.');
        }

        $recipients = $this->recipientEmails($org);
        if ($recipients->isEmpty()) {
            throw new \RuntimeException('No org admin / org email available for reminders.');
        }

        $invoice = $this->createDraftRenewalInvoice($sub);
        $pdf = $this->documents->buildPdfBinary($invoice);
        $filename = $this->documents->attachmentFilename($invoice);

        $replacements = [
            '{customer_name}' => (string) ($org->org_name ?: 'Customer'),
            '{company_code}' => (string) ($org->company_code ?: ''),
            '{plan_name}' => (string) ($sub->plan?->name ?: 'Centrix ERP'),
            '{expires_on}' => $expiresAt->toDateString(),
            '{days_remaining}' => (string) $daysRemaining,
            '{invoice_number}' => (string) ($invoice->invoice_number ?: '#'.$invoice->id),
            '{total}' => number_format((float) $invoice->total, 2).' '.($invoice->currency ?: 'KES'),
            '{from_name}' => (string) ($settings['from_name'] ?? 'Centrix'),
        ];

        $subjectTpl = (string) ($settings['renewal_email_subject']
            ?: 'Centrix ERP licence renewal reminder — {company_code}');
        $bodyTpl = (string) ($settings['renewal_email_body']
            ?: $this->defaultRenewalBody());

        $subject = strtr($subjectTpl, $replacements);
        $body = strtr($bodyTpl, $replacements);

        $attachments = [[
            'data' => $pdf,
            'name' => $filename,
            'mime' => 'application/pdf',
        ]];

        foreach ($recipients as $to) {
            $this->mailbox->send(
                $to,
                $subject,
                $body,
                null,
                [
                    'kind' => 'subscription_renewal_reminder',
                    'subscription_id' => $sub->id,
                    'invoice_id' => $invoice->id,
                    'organization_id' => $org->id,
                    'days_remaining' => $daysRemaining,
                ],
                null,
                $attachments,
            );
        }

        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'sent']);
        }
    }

    /**
     * Send a preview renewal reminder (sample invoice PDF) to a platform admin.
     *
     * @return array{to: string, subject: string, from_hint: string}
     */
    public function sendTestReminder(string $to, ?User $actor = null): array
    {
        $settings = PlatformMailSettingsResolver::resolve();
        if (! ($settings['enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'email' => ['Platform email delivery is disabled. Enable it under Email delivery first.'],
            ]);
        }

        $to = strtolower(trim($to));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages([
                'to' => ['Enter a valid test recipient email.'],
            ]);
        }

        $invoice = $this->sampleInvoice();
        $pdf = $this->documents->buildPdfBinary($invoice);
        $filename = $this->documents->attachmentFilename($invoice);

        $replacements = [
            '{customer_name}' => 'Sample Customer Ltd',
            '{company_code}' => 'SAMPLE',
            '{plan_name}' => 'Centrix ERP — Growth',
            '{expires_on}' => now()->addDays(14)->toDateString(),
            '{days_remaining}' => '14',
            '{invoice_number}' => (string) $invoice->invoice_number,
            '{total}' => number_format((float) $invoice->total, 2).' '.($invoice->currency ?: 'KES'),
            '{from_name}' => (string) ($settings['from_name'] ?? 'Centrix'),
        ];

        $subjectTpl = (string) ($settings['renewal_email_subject']
            ?: 'Centrix ERP licence renewal reminder — {company_code}');
        $bodyTpl = (string) ($settings['renewal_email_body']
            ?: $this->defaultRenewalBody());

        $subject = '[TEST] '.strtr($subjectTpl, $replacements);
        $body = strtr($bodyTpl, $replacements)
            ."\n\n---\nThis is a test renewal reminder from Centrix Platform settings. No customer was charged.\n";

        $this->mailbox->send(
            $to,
            $subject,
            $body,
            $actor,
            [
                'kind' => 'subscription_renewal_reminder_test',
            ],
            null,
            [[
                'data' => $pdf,
                'name' => $filename,
                'mime' => 'application/pdf',
            ]],
        );

        return [
            'to' => $to,
            'subject' => $subject,
            'from_hint' => (string) ($settings['from_address'] ?? ''),
        ];
    }

    protected function sampleInvoice(): PlatformInvoice
    {
        $seller = $this->billing->platformSeller();
        $amount = 25000.0;
        $invoice = new PlatformInvoice([
            'invoice_number' => 'PLT-TEST-RENEWAL',
            'status' => 'draft',
            'template_id' => 'modern',
            'currency' => 'KES',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'bill_to_name' => 'Sample Customer Ltd',
            'bill_to_email' => 'billing@sample.example',
            'bill_to_company_code' => 'SAMPLE',
            'bill_to_address' => 'Nairobi, Kenya',
            'seller' => $seller,
            'line_items' => [[
                'description' => 'Centrix ERP renewal — Growth (sample test invoice)',
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'included' => true,
            ]],
            'subtotal' => $amount,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => $amount,
            'notes' => 'Sample invoice for renewal reminder preview only.',
            'terms' => 'This is a test attachment — not a real bill.',
        ]);
        $invoice->id = 0;

        return $invoice;
    }

    /** @return Collection<int, string> */
    public function recipientEmails(Organization $org): Collection
    {
        $emails = collect();

        if (filter_var((string) $org->org_email, FILTER_VALIDATE_EMAIL)) {
            $emails->push(strtolower(trim((string) $org->org_email)));
        }

        $adminEmails = User::query()
            ->where('organization_id', $org->id)
            ->where('is_admin', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false);

        return $emails->merge($adminEmails)->unique()->values();
    }

    public function primaryRecipientEmail(Organization $org): ?string
    {
        return $this->recipientEmails($org)->first();
    }

    public function expiresAt(PlatformSubscription $sub): ?Carbon
    {
        $raw = $sub->is_trial && $sub->trial_ends_at
            ? $sub->trial_ends_at
            : $sub->current_period_end;

        return $raw ? Carbon::parse($raw)->endOfDay() : null;
    }

    /** @param  array<string, mixed>  $settings */
    public function reminderDays(array $settings): array
    {
        $raw = $settings['subscription_reminder_days'] ?? '30,14,7';
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[,\s]+/', (string) $raw) ?: [];
        }

        $days = [];
        foreach ($parts as $part) {
            if (is_numeric($part) && (int) $part >= 0) {
                $days[] = (int) $part;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    public function defaultRenewalBody(): string
    {
        return "Dear {customer_name},\n\n"
            ."Your Centrix ERP licence for {company_code} ({plan_name}) expires on {expires_on} "
            ."({days_remaining} day(s) remaining).\n\n"
            ."Please find attached invoice {invoice_number} for {total} to renew your subscription.\n\n"
            ."If you have already paid, you can ignore this message.\n\n"
            ."Regards,\n{from_name}";
    }
}
