<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\PlatformMailMessage;
use App\Services\Platform\PlatformInvoiceDocumentService;
use App\Services\Platform\PlatformMailboxService;
use App\Services\Platform\PlatformMailSettingsResolver;
use App\Services\Platform\PlatformMailStats;
use Illuminate\Http\Request;

class PlatformMailController extends Controller
{
    public function __construct(
        protected PlatformMailboxService $mailbox,
        protected PlatformInvoiceDocumentService $invoiceDocuments,
    ) {}

    public function show(Request $request)
    {
        $accountId = $request->query('account_id');

        return response()->json([
            'settings' => PlatformMailSettingsResolver::resolve(
                is_string($accountId) && $accountId !== '' ? $accountId : null
            ),
            'stats' => PlatformMailStats::summarize(),
        ]);
    }

    public function stats()
    {
        return response()->json([
            'stats' => PlatformMailStats::summarize(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'account_id' => 'nullable|string|max:64',
            'active_account_id' => 'nullable|string|max:64',
            'add_account' => 'nullable|array',
            'remove_account_id' => 'nullable|string|max:64',
            'set_default_account_id' => 'nullable|string|max:64',
            'prefill_imap_from_smtp' => 'sometimes|boolean',
            'label' => 'nullable|string|max:200',
            'enabled' => 'sometimes|boolean',
            'from_name' => 'sometimes|string|max:200',
            'from_address' => 'sometimes|nullable|email|max:200',
            'reply_to' => 'nullable|email|max:200',
            'noreply_address' => 'nullable|email|max:200',
            'auth_mail_use_dedicated' => 'sometimes|boolean',
            'auth_from_name' => 'nullable|string|max:200',
            'auth_from_address' => 'nullable|email|max:200',
            'auth_smtp_host' => 'nullable|string|max:200',
            'auth_smtp_port' => 'nullable|integer|min:1',
            'auth_smtp_username' => 'nullable|string|max:200',
            'auth_smtp_password' => 'nullable|string|max:500',
            'auth_smtp_encryption' => 'nullable|in:tls,ssl,none',
            'smtp_host' => 'nullable|string|max:200',
            'smtp_port' => 'nullable|integer|min:1',
            'smtp_username' => 'nullable|string|max:200',
            'smtp_password' => 'nullable|string|max:500',
            'smtp_encryption' => 'nullable|in:tls,ssl,none',
            'imap_enabled' => 'sometimes|boolean',
            'imap_host' => 'nullable|string|max:200',
            'imap_port' => 'nullable|integer|min:1',
            'imap_username' => 'nullable|string|max:200',
            'imap_password' => 'nullable|string|max:500',
            'imap_encryption' => 'nullable|in:tls,ssl,none',
            'imap_mailbox' => 'nullable|string|max:100',
            'contract_email_subject' => 'nullable|string|max:500',
            'contract_email_body' => 'nullable|string',
            'subscription_reminder_enabled' => 'sometimes|boolean',
            'subscription_reminder_days' => 'nullable|string|max:100',
            'renewal_email_subject' => 'nullable|string|max:500',
            'renewal_email_body' => 'nullable|string',
        ]);

        return response()->json([
            'settings' => PlatformMailSettingsResolver::save($data),
            'message' => 'Platform email settings saved.',
        ]);
    }

    public function test(Request $request)
    {
        $data = $request->validate(['to' => 'required|email']);
        $this->mailbox->send(
            $data['to'],
            'Centrix platform mail test',
            "This is a test email from Centrix platform mail settings.\n\nIf you received this, SMTP is working.",
            $request->user(),
            ['kind' => 'test'],
        );

        return response()->json(['message' => 'Test email sent.']);
    }

    public function testAuthMail(Request $request)
    {
        $data = $request->validate(['to' => 'required|email']);
        $this->mailbox->send(
            $data['to'],
            'Centrix 2FA / auth mail test',
            "This is a test of the dedicated 2FA / email-verification sender.\n\n"
            ."This is an automated message — please do not reply.\n",
            $request->user(),
            ['kind' => 'two_factor', 'no_reply' => true, 'purpose' => 'test'],
        );

        return response()->json(['message' => 'Auth / 2FA test email sent.']);
    }

    public function testRenewalReminder(Request $request)
    {
        $data = $request->validate(['to' => 'required|email']);

        $result = app(\App\Services\Platform\SubscriptionRenewalReminderService::class)
            ->sendTestReminder($data['to'], $request->user());

        return response()->json([
            'message' => 'Test renewal reminder sent to '.$result['to'].' (sample invoice PDF attached).',
            'subject' => $result['subject'],
        ]);
    }

    public function messages(Request $request)
    {
        $folder = $request->query('folder', 'inbox');
        $kind = trim((string) $request->query('kind', ''));
        $accountId = trim((string) $request->query('account_id', ''));
        $q = PlatformMailMessage::query()->orderByDesc('id');

        if ($accountId !== '') {
            $settings = PlatformMailSettingsResolver::resolve($accountId);
            $isDefault = collect($settings['accounts'] ?? [])->contains(
                fn ($row) => (string) ($row['id'] ?? '') === $accountId && ! empty($row['is_default'])
            );
            $q->where(function ($inner) use ($accountId, $isDefault) {
                $inner->where('mailbox_account_id', $accountId);
                if ($isDefault) {
                    $inner->orWhereNull('mailbox_account_id');
                }
            });
        }

        if ($folder === 'inbox') {
            $q->where('folder', 'inbox');
        } elseif ($folder === 'sent') {
            $q->where('folder', 'sent');
        } elseif ($folder === 'all') {
            // no filter
        } else {
            $q->where('folder', $folder);
        }

        if ($kind !== '') {
            $q->where('meta->kind', $kind);
        }

        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('subject', 'like', $term)
                    ->orWhere('from_address', 'like', $term)
                    ->orWhere('body_text', 'like', $term);
            });
        }

        $rows = $q->limit(100)->get()->map(function (PlatformMailMessage $message) {
            $payload = $message->toArray();
            $kind = is_array($message->meta) ? ($message->meta['kind'] ?? null) : null;
            $payload['kind'] = $kind;
            $payload['kind_label'] = PlatformMailStats::labelForKind(is_string($kind) ? $kind : null);

            return $payload;
        });

        $unreadQuery = PlatformMailMessage::query()
            ->where('folder', 'inbox')
            ->whereNull('read_at');
        if ($accountId !== '') {
            $settings = PlatformMailSettingsResolver::resolve($accountId);
            $isDefault = collect($settings['accounts'] ?? [])->contains(
                fn ($row) => (string) ($row['id'] ?? '') === $accountId && ! empty($row['is_default'])
            );
            $unreadQuery->where(function ($inner) use ($accountId, $isDefault) {
                $inner->where('mailbox_account_id', $accountId);
                if ($isDefault) {
                    $inner->orWhereNull('mailbox_account_id');
                }
            });
        }

        return response()->json([
            'data' => $rows,
            'unread_count' => $unreadQuery->count(),
            'stats' => PlatformMailStats::summarize(),
            'active_account_id' => $accountId !== '' ? $accountId : (PlatformMailSettingsResolver::resolve()['active_account_id'] ?? null),
            'accounts' => PlatformMailSettingsResolver::resolve()['accounts'] ?? [],
        ]);
    }

    public function showMessage(PlatformMailMessage $message)
    {
        if ($message->folder === 'inbox' && ! $message->read_at) {
            $message->update(['read_at' => now()]);
        }

        $thread = PlatformMailMessage::query()
            ->where(function ($q) use ($message) {
                $q->where('thread_key', $message->thread_key)
                    ->orWhere('message_id', $message->thread_key)
                    ->orWhere('in_reply_to', $message->message_id)
                    ->orWhere('id', $message->id);
            })
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => array_merge($message->fresh()->toArray(), [
                'kind' => is_array($message->meta) ? ($message->meta['kind'] ?? null) : null,
                'kind_label' => PlatformMailStats::labelForKind(
                    is_array($message->meta) && is_string($message->meta['kind'] ?? null)
                        ? $message->meta['kind']
                        : null
                ),
            ]),
            'thread' => $thread,
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string|max:500',
            'body' => 'required|string',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'invoice_id' => 'nullable|integer|exists:platform_invoices,id',
            'account_id' => 'nullable|string|max:64',
        ]);

        $attachments = [];
        $invoice = null;
        if (! empty($data['invoice_id'])) {
            $invoice = PlatformInvoice::query()->with('organization')->findOrFail($data['invoice_id']);
            if (! empty($data['organization_id'])
                && (int) $invoice->organization_id !== (int) $data['organization_id']) {
                return response()->json([
                    'message' => 'Selected invoice does not belong to the selected organization.',
                ], 422);
            }
            $attachments[] = [
                'data' => $this->invoiceDocuments->buildPdfBinary($invoice),
                'name' => $this->invoiceDocuments->attachmentFilename($invoice),
                'mime' => 'application/pdf',
            ];
            if (empty($data['organization_id']) && $invoice->organization_id) {
                $data['organization_id'] = $invoice->organization_id;
            }
        }

        if (! empty($data['account_id'])) {
            PlatformMailSettingsResolver::save(['active_account_id' => $data['account_id']]);
        }

        $msg = $this->mailbox->send(
            $data['to'],
            $data['subject'],
            $data['body'],
            $request->user(),
            array_filter([
                'organization_id' => $data['organization_id'] ?? null,
                'invoice_id' => $invoice?->id,
                'kind' => $invoice ? 'invoice' : 'compose',
                'mailbox_account_id' => $data['account_id'] ?? null,
            ], fn ($v) => $v !== null),
            null,
            $attachments,
        );

        if ($invoice && $invoice->status === 'draft') {
            $invoice->update(['status' => 'sent']);
        }

        return response()->json([
            'data' => $msg,
            'message' => $invoice
                ? 'Email sent with invoice PDF attached.'
                : 'Email sent.',
        ], 201);
    }

    public function reply(Request $request, PlatformMailMessage $message)
    {
        $data = $request->validate([
            'body' => 'required|string',
            'subject' => 'nullable|string|max:500',
            'to' => 'nullable|email',
        ]);

        $to = $data['to']
            ?? ($message->direction === 'inbound'
                ? $message->from_address
                : (is_array($message->to_addresses) ? ($message->to_addresses[0] ?? null) : null));

        if (! $to) {
            return response()->json(['message' => 'No recipient address for reply.'], 422);
        }

        $subject = $data['subject'] ?? $message->subject ?? '';
        if ($subject !== '' && ! preg_match('/^re:\s/i', $subject)) {
            $subject = 'Re: '.$subject;
        }

        $msg = $this->mailbox->send(
            $to,
            $subject,
            $data['body'],
            $request->user(),
            ['kind' => 'reply', 'organization_id' => $message->organization_id],
            $message,
        );

        return response()->json(['data' => $msg, 'message' => 'Reply sent.'], 201);
    }

    public function markRead(PlatformMailMessage $message)
    {
        $message->update(['read_at' => now()]);

        return response()->json(['data' => $message->fresh()]);
    }

    public function sync(Request $request)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'account_id' => 'nullable|string|max:64',
        ]);
        $limit = (int) ($data['limit'] ?? 50);
        $accountId = $data['account_id'] ?? null;
        if ($accountId) {
            PlatformMailSettingsResolver::save(['active_account_id' => $accountId]);
        }
        $result = $this->mailbox->syncInbox($limit, $accountId);

        $status = ($result['ok'] ?? true) ? 200 : 422;

        return response()->json($result, $status);
    }

    public function testImap(Request $request)
    {
        $data = $request->validate([
            'account_id' => 'nullable|string|max:64',
        ]);
        $result = $this->mailbox->testImapConnection($data['account_id'] ?? null);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function listComposeTemplates()
    {
        return response()->json([
            'data' => PlatformMailSettingsResolver::listComposeTemplates(),
        ]);
    }

    public function storeComposeTemplate(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|string|max:64',
            'name' => 'required|string|max:120',
            'subject' => 'nullable|string|max:500',
            'body' => 'nullable|string|max:50000',
        ]);

        return response()->json([
            'data' => PlatformMailSettingsResolver::saveComposeTemplate($data),
            'message' => 'Email template saved.',
        ], empty($data['id']) ? 201 : 200);
    }

    public function destroyComposeTemplate(string $template)
    {
        return response()->json([
            'data' => PlatformMailSettingsResolver::deleteComposeTemplate($template),
            'message' => 'Email template deleted.',
        ]);
    }
}
