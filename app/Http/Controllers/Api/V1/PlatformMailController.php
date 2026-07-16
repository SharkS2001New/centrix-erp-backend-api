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
            'imap_sync_filter' => 'nullable|in:primary,updates,all',
            'contract_email_subject' => 'nullable|string|max:500',
            'contract_email_body' => 'nullable|string',
            'subscription_reminder_enabled' => 'sometimes|boolean',
            'subscription_reminder_days' => 'nullable|string|max:100',
            'renewal_email_subject' => 'nullable|string|max:500',
            'renewal_email_body' => 'nullable|string',
            'renewal_invoice_design_id' => 'nullable|string|max:40',
            'renewal_invoice_saved_template_id' => 'nullable|integer|exists:platform_invoice_saved_templates,id',
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
                    ->orWhere('from_name', 'like', $term)
                    ->orWhere('body_text', 'like', $term)
                    ->orWhere('to_addresses', 'like', $term);
            });
        }

        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        $offset = max(0, (int) $request->query('offset', 0));
        $total = (clone $q)->count();

        $rows = $q->offset($offset)->limit($limit)->get()->map(function (PlatformMailMessage $message) {
            $payload = $message->toArray();
            $kind = is_array($message->meta) ? ($message->meta['kind'] ?? null) : null;
            $payload['kind'] = $kind;
            $payload['kind_label'] = PlatformMailStats::labelForKind(is_string($kind) ? $kind : null);
            $payload['body_is_snippet'] = is_array($message->meta)
                && (($message->meta['body_storage'] ?? '') === 'imap_snippet');
            // List views only need a short preview.
            $payload['body_text'] = \Illuminate\Support\Str::limit((string) ($message->body_text ?? ''), 500, '…');

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
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $rows->count()) < $total,
            'stats' => PlatformMailStats::summarize(),
            'active_account_id' => $accountId !== '' ? $accountId : (PlatformMailSettingsResolver::resolve()['active_account_id'] ?? null),
            'accounts' => PlatformMailSettingsResolver::resolve()['accounts'] ?? [],
        ]);
    }

    public function showMessage(PlatformMailMessage $message)
    {
        if ($message->folder === 'inbox' && ! $message->read_at) {
            $message->update(['read_at' => now()]);
            $message->refresh();
        }

        $bodyFromImap = false;
        $bodyDetail = null;
        $displayBody = (string) ($message->body_text ?? '');
        $meta = is_array($message->meta) ? $message->meta : [];
        $needsImapBody = $message->direction === 'inbound'
            && (
                ($meta['body_storage'] ?? '') === 'imap_snippet'
                || (trim((string) ($message->imap_uid ?? '')) !== '' && mb_strlen($displayBody) <= 520)
            );

        if ($needsImapBody) {
            $fetched = $this->mailbox->fetchRemoteBody($message);
            $bodyDetail = $fetched['message'] ?? null;
            if (($fetched['ok'] ?? false) && is_string($fetched['body'] ?? null) && $fetched['body'] !== '') {
                $displayBody = $fetched['body'];
                $bodyFromImap = true;
                // Persist so reopen does not depend on IMAP UID/sequence drift.
                $message->update([
                    'body_text' => $displayBody,
                    'meta' => array_merge($meta, [
                        'body_storage' => 'local',
                        'body_fetched_at' => now()->toIso8601String(),
                        'body_snippet_len' => null,
                    ]),
                ]);
                $meta = is_array($message->meta) ? $message->meta : $meta;
            }
        }

        $thread = PlatformMailMessage::query()
            ->where(function ($q) use ($message) {
                $q->where('thread_key', $message->thread_key)
                    ->orWhere('message_id', $message->thread_key)
                    ->orWhere('in_reply_to', $message->message_id)
                    ->orWhere('id', $message->id);
            })
            ->orderBy('id')
            ->get()
            ->map(function (PlatformMailMessage $row) use ($message, $displayBody, $bodyFromImap) {
                $payload = $row->toArray();
                if ($row->id === $message->id && $bodyFromImap) {
                    $payload['body_text'] = $displayBody;
                    $payload['body_from_imap'] = true;
                } elseif (is_array($row->meta) && ($row->meta['body_storage'] ?? '') === 'imap_snippet') {
                    $payload['body_is_snippet'] = true;
                }

                return $payload;
            });

        $data = array_merge($message->fresh()->toArray(), [
            'body_text' => $displayBody,
            'body_from_imap' => $bodyFromImap,
            'body_is_snippet' => ! $bodyFromImap && ($meta['body_storage'] ?? '') === 'imap_snippet',
            'body_imap_detail' => $bodyDetail,
            'kind' => $meta['kind'] ?? null,
            'kind_label' => PlatformMailStats::labelForKind(
                is_string($meta['kind'] ?? null) ? $meta['kind'] : null
            ),
            'saved_for_ai' => (bool) ($meta['reply_memory']['use_for_ai'] ?? false),
        ]);

        return response()->json([
            'data' => $data,
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
            'draft_id' => 'nullable|integer|exists:platform_mail_messages,id',
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

        if (! empty($data['draft_id'])) {
            $draft = PlatformMailMessage::query()->find($data['draft_id']);
            if ($draft && $draft->folder === 'drafts') {
                $draft->delete();
            }
        }

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

    public function saveDraft(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:platform_mail_messages,id',
            'to' => 'nullable|email',
            'subject' => 'nullable|string|max:500',
            'body' => 'nullable|string',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'invoice_id' => 'nullable|integer|exists:platform_invoices,id',
            'account_id' => 'nullable|string|max:64',
        ]);

        $to = $data['to'] ?? null;
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = (string) ($data['body'] ?? '');
        if (($to === null || $to === '') && $subject === '' && trim($body) === '') {
            return response()->json(['message' => 'Write a recipient, subject, or body before saving a draft.'], 422);
        }

        $accountId = $data['account_id']
            ?? PlatformMailSettingsResolver::resolve()['active_account_id']
            ?? null;
        $meta = array_filter([
            'organization_id' => $data['organization_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'kind' => 'draft',
        ], fn ($v) => $v !== null);

        $settings = PlatformMailSettingsResolver::resolve(is_string($accountId) ? $accountId : null);

        if (! empty($data['id'])) {
            $draft = PlatformMailMessage::query()->findOrFail($data['id']);
            if ($draft->folder !== 'drafts') {
                return response()->json(['message' => 'Only drafts can be updated this way.'], 422);
            }
            $draft->update([
                'mailbox_account_id' => $accountId,
                'from_address' => $settings['from_address'] ?? $draft->from_address,
                'from_name' => $settings['from_name'] ?? $draft->from_name,
                'to_addresses' => $to ? [$to] : [],
                'subject' => $subject !== '' ? $subject : null,
                'body_text' => $body,
                'organization_id' => $data['organization_id'] ?? null,
                'meta' => $meta ?: null,
            ]);

            return response()->json([
                'data' => $draft->fresh(),
                'message' => 'Draft updated.',
            ]);
        }

        $draft = PlatformMailMessage::query()->create([
            'direction' => 'outbound',
            'folder' => 'drafts',
            'mailbox_account_id' => $accountId,
            'thread_key' => 'draft-'.\Illuminate\Support\Str::uuid(),
            'message_id' => null,
            'from_address' => $settings['from_address'] ?? '',
            'from_name' => $settings['from_name'] ?? null,
            'to_addresses' => $to ? [$to] : [],
            'subject' => $subject !== '' ? $subject : null,
            'body_text' => $body,
            'organization_id' => $data['organization_id'] ?? null,
            'sent_by_user_id' => $request->user()?->id,
            'meta' => $meta ?: null,
        ]);

        return response()->json([
            'data' => $draft,
            'message' => 'Draft saved.',
        ], 201);
    }

    public function destroyMessage(PlatformMailMessage $message)
    {
        $wasUnread = $message->folder === 'inbox' && $message->read_at === null;
        $accountId = $message->mailbox_account_id ? (string) $message->mailbox_account_id : null;

        $remote = $this->mailbox->deleteRemoteMessage($message);

        $message->delete();

        $unreadQuery = PlatformMailMessage::query()
            ->where('folder', 'inbox')
            ->whereNull('read_at');
        if ($accountId) {
            $unreadQuery->where(function ($inner) use ($accountId) {
                $inner->where('mailbox_account_id', $accountId)->orWhereNull('mailbox_account_id');
            });
        }

        return response()->json([
            'message' => ($remote['remote'] ?? false)
                ? 'Message deleted from mailbox and email account.'
                : 'Message deleted.',
            'remote_deleted' => (bool) ($remote['remote'] ?? false),
            'remote_detail' => $remote['message'] ?? null,
            'was_unread' => $wasUnread,
            'unread_count' => $unreadQuery->count(),
        ]);
    }

    public function destroyMessages(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|distinct|exists:platform_mail_messages,id',
            'account_id' => 'nullable|string|max:64',
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $messages = PlatformMailMessage::query()
            ->whereIn('id', $ids)
            ->get();

        $deleted = 0;
        $remoteDeleted = 0;

        foreach ($messages as $message) {
            $remote = $this->mailbox->deleteRemoteMessage($message);
            if ($remote['remote'] ?? false) {
                $remoteDeleted++;
            }
            $message->delete();
            $deleted++;
        }

        $accountId = trim((string) ($data['account_id'] ?? ''));
        $unreadQuery = PlatformMailMessage::query()
            ->where('folder', 'inbox')
            ->whereNull('read_at');
        if ($accountId !== '') {
            $unreadQuery->where(function ($inner) use ($accountId) {
                $inner->where('mailbox_account_id', $accountId)->orWhereNull('mailbox_account_id');
            });
        }

        $message = $deleted === 1
            ? ($remoteDeleted > 0
                ? 'Message deleted from mailbox and email account.'
                : 'Message deleted.')
            : ($remoteDeleted > 0
                ? "{$deleted} messages deleted ({$remoteDeleted} also removed from the email account)."
                : "{$deleted} messages deleted.");

        return response()->json([
            'message' => $message,
            'deleted' => $deleted,
            'remote_deleted' => $remoteDeleted,
            'unread_count' => $unreadQuery->count(),
        ]);
    }

    public function similarReplies(PlatformMailMessage $message)
    {
        $subject = trim((string) preg_replace('/^(re|fw|fwd):\s*/i', '', (string) ($message->subject ?? '')));
        $from = strtolower(trim((string) ($message->from_address ?? '')));

        // Only examples the admin explicitly saved for AI reply memory.
        $query = PlatformMailMessage::query()
            ->where('folder', 'sent')
            ->where('direction', 'outbound')
            ->where('meta->reply_memory->use_for_ai', true)
            ->orderByDesc('id')
            ->limit(12);

        if ($from !== '' || $subject !== '') {
            $query->where(function ($q) use ($from, $subject) {
                if ($from !== '') {
                    $q->orWhere('to_addresses', 'like', '%'.$from.'%')
                        ->orWhere('meta->reply_memory->from_address', $from);
                }
                if ($subject !== '' && mb_strlen($subject) >= 4) {
                    $q->orWhere('subject', 'like', '%'.$subject.'%')
                        ->orWhere('meta->reply_memory->subject_norm', 'like', '%'.$subject.'%');
                }
            });
        }

        $rows = $query->get(['id', 'subject', 'body_text', 'to_addresses', 'sent_at', 'in_reply_to', 'meta'])
            ->map(fn (PlatformMailMessage $row) => [
                'id' => $row->id,
                'subject' => $row->subject,
                'body_text' => \Illuminate\Support\Str::limit((string) $row->body_text, 1200),
                'to_addresses' => $row->to_addresses,
                'sent_at' => optional($row->sent_at)?->toIso8601String(),
                'inbound_snippet' => is_array($row->meta)
                    ? ($row->meta['reply_memory']['inbound_snippet'] ?? null)
                    : null,
                'saved_for_ai' => true,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'inbound' => [
                'id' => $message->id,
                'subject' => $message->subject,
                'from_address' => $message->from_address,
                'from_name' => $message->from_name,
                'body_text' => \Illuminate\Support\Str::limit((string) $message->body_text, 2000),
            ],
        ]);
    }

    /**
     * Opt-in: mark this sent reply as an AI memory example for similar future emails.
     */
    public function saveReplyMemory(Request $request, PlatformMailMessage $message)
    {
        if ($message->direction !== 'outbound' || $message->folder !== 'sent') {
            return response()->json(['message' => 'Only sent replies can be saved for AI memory.'], 422);
        }

        $data = $request->validate([
            'inbound_message_id' => 'nullable|integer|exists:platform_mail_messages,id',
        ]);

        $inbound = null;
        $inboundId = $data['inbound_message_id'] ?? null;
        if ($inboundId) {
            $inbound = PlatformMailMessage::query()->find($inboundId);
        }
        if (! $inbound && $message->in_reply_to) {
            $inbound = PlatformMailMessage::query()
                ->where('message_id', $message->in_reply_to)
                ->orWhere('message_id', trim((string) $message->in_reply_to, " \t<>"))
                ->first();
        }
        if (! $inbound && is_array($message->meta) && ! empty($message->meta['inbound_message_id'])) {
            $inbound = PlatformMailMessage::query()->find($message->meta['inbound_message_id']);
        }

        $subjectNorm = trim((string) preg_replace(
            '/^(re|fw|fwd):\s*/i',
            '',
            (string) ($inbound?->subject ?? $message->subject ?? '')
        ));
        $from = strtolower(trim((string) (
            $inbound?->from_address
            ?? (is_array($message->to_addresses) ? ($message->to_addresses[0] ?? '') : '')
        )));

        $meta = is_array($message->meta) ? $message->meta : [];
        $meta['kind'] = $meta['kind'] ?? 'reply';
        $meta['reply_memory'] = [
            'use_for_ai' => true,
            'saved_at' => now()->toIso8601String(),
            'expires_at' => now()->addMonths(3)->toIso8601String(),
            'from_address' => $from,
            'subject_norm' => $subjectNorm,
            'inbound_snippet' => \Illuminate\Support\Str::limit((string) ($inbound?->body_text ?? ''), 500),
            'inbound_message_id' => $inbound?->id,
        ];

        $message->update(['meta' => $meta]);

        return response()->json([
            'data' => $message->fresh(),
            'saved_for_ai' => true,
            'message' => 'Saved for future similar responses (kept up to 3 months).',
        ]);
    }

    public function clearReplyMemory(PlatformMailMessage $message)
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        if (isset($meta['reply_memory']) && is_array($meta['reply_memory'])) {
            $meta['reply_memory']['use_for_ai'] = false;
            $meta['reply_memory']['cleared_at'] = now()->toIso8601String();
        } else {
            $meta['reply_memory'] = ['use_for_ai' => false];
        }
        $message->update(['meta' => $meta]);

        return response()->json([
            'data' => $message->fresh(),
            'saved_for_ai' => false,
            'message' => 'Removed from future AI responses.',
        ]);
    }

    public function reply(Request $request, PlatformMailMessage $message)
    {
        $data = $request->validate([
            'body' => 'required|string',
            'subject' => 'nullable|string|max:500',
            'to' => 'nullable|email',
            'save_for_ai' => 'sometimes|boolean',
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

        $subjectNorm = trim((string) preg_replace('/^(re|fw|fwd):\s*/i', '', (string) ($message->subject ?? '')));
        $saveForAi = (bool) ($data['save_for_ai'] ?? false);

        $meta = [
            'kind' => 'reply',
            'organization_id' => $message->organization_id,
            'inbound_message_id' => $message->id,
        ];

        // Only store as AI memory when the admin opts in (auto-pruned after ~3 months).
        if ($saveForAi) {
            $meta['reply_memory'] = [
                'use_for_ai' => true,
                'saved_at' => now()->toIso8601String(),
                'expires_at' => now()->addMonths(3)->toIso8601String(),
                'from_address' => strtolower(trim((string) ($message->from_address ?? ''))),
                'subject_norm' => $subjectNorm,
                'inbound_snippet' => \Illuminate\Support\Str::limit((string) ($message->body_text ?? ''), 500),
                'inbound_message_id' => $message->id,
            ];
        }

        $msg = $this->mailbox->send(
            $to,
            $subject,
            $data['body'],
            $request->user(),
            $meta,
            $message,
        );

        return response()->json([
            'data' => $msg,
            'saved_for_ai' => $saveForAi,
            'message' => $saveForAi
                ? 'Reply sent and saved for AI suggestions.'
                : 'Reply sent.',
        ], 201);
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
