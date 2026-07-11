<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\PlatformMailMessage;
use App\Services\Platform\PlatformInvoiceDocumentService;
use App\Services\Platform\PlatformMailboxService;
use App\Services\Platform\PlatformMailSettingsResolver;
use Illuminate\Http\Request;

class PlatformMailController extends Controller
{
    public function __construct(
        protected PlatformMailboxService $mailbox,
        protected PlatformInvoiceDocumentService $invoiceDocuments,
    ) {}

    public function show()
    {
        return response()->json([
            'settings' => PlatformMailSettingsResolver::resolve(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'from_name' => 'sometimes|string|max:200',
            'from_address' => 'sometimes|email|max:200',
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
        $q = PlatformMailMessage::query()->orderByDesc('id');

        if ($folder === 'inbox') {
            $q->where('folder', 'inbox');
        } elseif ($folder === 'sent') {
            $q->where('folder', 'sent');
        } elseif ($folder === 'all') {
            // no filter
        } else {
            $q->where('folder', $folder);
        }

        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('subject', 'like', $term)
                    ->orWhere('from_address', 'like', $term)
                    ->orWhere('body_text', 'like', $term);
            });
        }

        $rows = $q->limit(100)->get();

        return response()->json([
            'data' => $rows,
            'unread_count' => PlatformMailMessage::query()
                ->where('folder', 'inbox')
                ->whereNull('read_at')
                ->count(),
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
            'data' => $message->fresh(),
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

        $msg = $this->mailbox->send(
            $data['to'],
            $data['subject'],
            $data['body'],
            $request->user(),
            array_filter([
                'organization_id' => $data['organization_id'] ?? null,
                'invoice_id' => $invoice?->id,
                'kind' => $invoice ? 'invoice' : 'compose',
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
        $limit = (int) $request->input('limit', 50);
        $result = $this->mailbox->syncInbox($limit);

        return response()->json($result);
    }
}
