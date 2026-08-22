<?php

namespace App\Services\Platform;

use App\Models\PlatformMailMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\IdentificationHeader;

class PlatformMailboxService
{
    public function send(
        string $to,
        string $subject,
        string $body,
        ?User $user = null,
        array $meta = [],
        ?PlatformMailMessage $replyTo = null,
        array $attachments = [],
        array $cc = [],
    ): PlatformMailMessage {
        $kind = (string) ($meta['kind'] ?? '');
        $isAuthMail = (bool) ($meta['no_reply'] ?? false)
            || in_array($kind, ['two_factor', 'email_verification'], true);

        $accountId = isset($meta['mailbox_account_id'])
            ? (string) $meta['mailbox_account_id']
            : ($replyTo?->mailbox_account_id);

        $settings = $isAuthMail
            ? PlatformMailSettingsResolver::resolveForAuth()
            : PlatformMailSettingsResolver::resolve($accountId);

        if (! $isAuthMail) {
            $accountId = (string) ($settings['account_id'] ?? $accountId ?? '');
        }

        $messageIdBody = Str::uuid()->toString().'@centrix.platform';
        $messageId = '<'.$messageIdBody.'>';
        $threadKey = $replyTo?->thread_key
            ?? $replyTo?->message_id
            ?? $messageId;

        $profile = ($isAuthMail && ($settings['auth_profile'] ?? '') === 'auth') ? 'auth' : 'default';
        PlatformMailSettingsResolver::applyMailConfig($profile, $isAuthMail ? null : $accountId);

        if ($isAuthMail && config('mail.default') !== 'smtp') {
            abort(
                422,
                '2FA email SMTP is not active on the server (mail would only be logged, not delivered). '
                .'Set SMTP host, port, and credentials under Platform → Settings → Email delivery, then send an Auth / 2FA test email.',
            );
        }

        if (! ($settings['enabled'] ?? false)) {
            if ($isAuthMail && ($settings['auth_profile'] ?? '') === 'auth') {
                abort(422, 'Dedicated 2FA email SMTP is enabled but not fully configured. Set Auth / 2FA email under Settings → Email delivery.');
            }
            abort(422, 'Platform outbound email is disabled. Enable it under Settings → Email delivery.');
        }

        $isNoReply = $isAuthMail || (bool) ($settings['no_reply'] ?? false);

        $fromAddress = (string) ($settings['from_address'] ?? '');
        $fromName = (string) ($settings['from_name'] ?? 'Centrix');
        if ($isNoReply && ($settings['auth_profile'] ?? 'default') === 'default') {
            $main = PlatformMailSettingsResolver::resolve($accountId);
            $candidate = $fromAddress !== ''
                ? $fromAddress
                : $this->deriveNoreplyAddress((string) ($main['from_address'] ?? ''));
            if ($this->smtpAllowsCustomFrom($main, $candidate)) {
                $fromAddress = $candidate;
            } else {
                $fromAddress = (string) ($main['from_address'] ?? $fromAddress);
            }
        }

        $inReplyTo = $replyTo?->message_id;
        $ccList = collect($cc)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->reject(fn ($email) => $email === strtolower(trim($to)))
            ->unique()
            ->values()
            ->all();

        try {
            \Illuminate\Support\Facades\Mail::raw($body, function ($message) use (
            $to,
            $subject,
            $settings,
            $messageIdBody,
            $inReplyTo,
            $attachments,
            $isNoReply,
            $fromAddress,
            $fromName,
            $ccList,
        ) {
            $message->to($to)->subject($subject);
            if ($ccList !== []) {
                $message->cc($ccList);
            }
            if ($fromAddress !== '') {
                $message->from($fromAddress, $fromName !== '' ? $fromName : null);
            }
            $this->setIdentificationHeaders(
                $message->getHeaders(),
                $messageIdBody,
                $inReplyTo ? trim((string) $inReplyTo, " \t<>") : null,
            );
            // Auth / verification mail must not invite replies.
            if (! $isNoReply && ! empty($settings['reply_to'])) {
                $message->replyTo($settings['reply_to']);
            }
            foreach ($attachments as $attachment) {
                $data = $attachment['data'] ?? null;
                $name = $attachment['name'] ?? 'attachment.bin';
                $mime = $attachment['mime'] ?? 'application/octet-stream';
                if (is_string($data) && $data !== '') {
                    $message->attachData($data, $name, ['mime' => $mime]);
                }
            }
        });
        } catch (\Throwable $e) {
            $this->reportMailFailure($e->getMessage(), $to, $subject, $kind, $user, $e);
            throw $e;
        }

        return PlatformMailMessage::query()->create([
            'direction' => 'outbound',
            'folder' => 'sent',
            'mailbox_account_id' => $isAuthMail ? null : ($accountId !== '' ? $accountId : null),
            'thread_key' => $threadKey,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'from_address' => $fromAddress !== '' ? $fromAddress : ($settings['from_address'] ?? ''),
            'from_name' => $fromName !== '' ? $fromName : ($settings['from_name'] ?? null),
            'to_addresses' => [$to],
            'cc_addresses' => $ccList !== [] ? $ccList : null,
            'subject' => $subject,
            'body_text' => $this->bodyForMailboxStorage($body, $meta),
            'organization_id' => $meta['organization_id'] ?? $replyTo?->organization_id,
            'contract_id' => $meta['contract_id'] ?? null,
            'sent_by_user_id' => $user?->id,
            'read_at' => now(),
            'sent_at' => now(),
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * Symfony rejects Message-ID / In-Reply-To / References set via addTextHeader
     * (UnstructuredHeader). Always use IdentificationHeader / addIdHeader.
     */
    protected function setIdentificationHeaders(Headers $headers, string $messageIdBody, ?string $inReplyToId = null): void
    {
        foreach (['Message-ID', 'In-Reply-To', 'References'] as $name) {
            if ($headers->has($name)) {
                $headers->remove($name);
            }
        }

        $headers->add(new IdentificationHeader('Message-ID', $messageIdBody));

        $replyId = trim((string) $inReplyToId);
        if ($replyId !== '') {
            $headers->add(new IdentificationHeader('In-Reply-To', $replyId));
            $headers->add(new IdentificationHeader('References', $replyId));
        }
    }

    /**
     * Store a safe copy in Sent (OTP codes are redacted; the real email already went out).
     *
     * @param  array<string, mixed>  $meta
     */
    protected function bodyForMailboxStorage(string $body, array $meta): string
    {
        $kind = (string) ($meta['kind'] ?? '');
        if (! in_array($kind, ['two_factor', 'email_verification'], true)) {
            return $body;
        }

        $redacted = preg_replace('/\b\d{4,8}\b/', '******', $body);

        return is_string($redacted) ? $redacted : $body;
    }

    protected function deriveNoreplyAddress(string $fromAddress): string
    {
        $fromAddress = trim($fromAddress);
        if ($fromAddress === '' || ! str_contains($fromAddress, '@')) {
            return 'noreply@centrixerp.com';
        }
        [, $domain] = explode('@', $fromAddress, 2);

        return 'noreply@'.strtolower(trim($domain));
    }

    /**
     * Whether SMTP is likely to accept this From address (Gmail is strict).
     *
     * @param  array<string, mixed>  $settings
     */
    protected function smtpAllowsCustomFrom(array $settings, string $candidateFrom): bool
    {
        $candidateFrom = strtolower(trim($candidateFrom));
        $fromAddress = strtolower(trim((string) ($settings['from_address'] ?? '')));
        $smtpUser = strtolower(trim((string) ($settings['smtp_username'] ?? '')));
        $host = strtolower(trim((string) ($settings['smtp_host'] ?? '')));

        if ($candidateFrom === '' || $candidateFrom === $fromAddress || $candidateFrom === $smtpUser) {
            return true;
        }

        $isGmail = str_contains($host, 'gmail.com')
            || str_contains($host, 'googlemail.com')
            || str_ends_with($smtpUser, '@gmail.com')
            || str_ends_with($smtpUser, '@googlemail.com');

        if (! $isGmail) {
            return true;
        }

        // Same mailbox local-part variants are fine; different domains are not for Gmail SMTP.
        $candidateDomain = str_contains($candidateFrom, '@') ? explode('@', $candidateFrom, 2)[1] : '';
        $accountDomain = str_contains($smtpUser, '@')
            ? explode('@', $smtpUser, 2)[1]
            : (str_contains($fromAddress, '@') ? explode('@', $fromAddress, 2)[1] : '');

        return $candidateDomain !== '' && $accountDomain !== '' && $candidateDomain === $accountDomain;
    }

    /**
     * Dry-run IMAP connect for the given (or active) mailbox account.
     *
     * @return array{ok: bool, message: string, detail?: string|null, account_id?: string|null}
     */
    public function testImapConnection(?string $accountId = null): array
    {
        if (! extension_loaded('imap')) {
            return [
                'ok' => false,
                'message' => 'PHP IMAP extension is not installed on the API server. Install php-imap, then try again.',
                'detail' => null,
                'account_id' => $accountId,
            ];
        }

        $stored = PlatformMailSettingsResolver::ensureAccounts(PlatformMailSettingsResolver::rawStored());
        $account = PlatformMailSettingsResolver::findAccount($stored, $accountId);
        if (! $account) {
            return [
                'ok' => false,
                'message' => 'Mailbox account not found.',
                'detail' => 'Add a mailbox under Platform settings → Email delivery.',
                'account_id' => $accountId,
            ];
        }

        $accountId = (string) ($account['id'] ?? '');

        if (empty($account['imap_enabled'])) {
            return [
                'ok' => false,
                'code' => 'imap_disabled',
                'message' => 'IMAP is turned off for this mailbox.',
                'detail' => 'Inbox sync is optional. Leave IMAP off if you only send mail, or if this mailbox is unused.',
                'account_id' => $accountId,
            ];
        }

        $account = PlatformMailSettingsResolver::prefillImapFromSmtp($account);
        if (empty($account['imap_host'])) {
            return [
                'ok' => false,
                'message' => 'IMAP host is missing.',
                'detail' => 'IMAP often matches your SMTP mailbox. Use “Copy from SMTP”, save, then test again — or enter the correct IMAP host.',
                'account_id' => $accountId,
            ];
        }

        $password = $account['imap_password'] ?? $account['smtp_password'] ?? null;
        $username = trim((string) ($account['imap_username'] ?? ''))
            ?: trim((string) ($account['smtp_username'] ?? ''));
        if (! $password || $username === '') {
            return [
                'ok' => false,
                'message' => 'IMAP username or password is missing.',
                'detail' => 'If IMAP uses the same login as SMTP, leave the IMAP password blank (SMTP password is reused) and set the username. Otherwise enter the correct IMAP credentials and save.',
                'account_id' => $accountId,
            ];
        }

        [$mailbox] = $this->imapMailboxPath($account);
        $inbox = false;
        $err = 'Could not connect to IMAP server.';
        try {
            $this->clearImapErrors();
            $inbox = @imap_open($mailbox, $username, (string) $password, 0, 1);
            if (! $inbox) {
                $err = (function_exists('imap_last_error') ? imap_last_error() : null) ?: $err;
            }
        } finally {
            $this->clearImapErrors();
        }
        if (! $inbox) {
            $detail = $err.' — If this mailbox matches SMTP, confirm the app password works for IMAP, then update IMAP settings and try again.';
            $isZoho = PlatformMailSettingsResolver::isZohoMailHost($account['imap_host'] ?? null)
                || PlatformMailSettingsResolver::isZohoMailHost($account['smtp_host'] ?? null);
            if ($isZoho || preg_match('/auth|login|credentials|password|authenticate/i', $err)) {
                $detail = $err
                    ."\n\nZoho IMAP checklist:"
                    ."\n• Host must be IMAP (not SMTP): imap.zoho.com / imap.zoho.eu / imap.zoho.in — or imappro.zoho.com for Zoho Mail Plus / custom domain"
                    ."\n• Port 993, encryption SSL"
                    ."\n• Username = full email address"
                    ."\n• Enable IMAP Access in Zoho Mail → Settings → Mail Accounts"
                    ."\n• If 2FA is on, create an Application-Specific Password and use that (normal Zoho password often fails IMAP)"
                    ."\n• Match your Zoho data center region (.com / .eu / .in)";
            }

            return [
                'ok' => false,
                'message' => 'IMAP refused the connection. Check host, port, encryption, and credentials.',
                'detail' => $detail,
                'account_id' => $accountId,
            ];
        }
        try {
            @imap_close($inbox);
        } finally {
            $this->clearImapErrors();
        }

        return [
            'ok' => true,
            'message' => 'Connected to IMAP as '.$username.'.',
            'detail' => 'Mailbox '.$mailbox,
            'account_id' => $accountId,
        ];
    }

    /** @return array{imported: int, skipped: int, message: string, ok?: bool, detail?: string|null} */
    public function syncInbox(int $limit = 50, ?string $accountId = null): array
    {
        $test = $this->testImapConnection($accountId);
        if (! ($test['ok'] ?? false)) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'ok' => false,
                'code' => $test['code'] ?? 'imap_error',
                'message' => $test['message'],
                'detail' => $test['detail'] ?? null,
            ];
        }

        $stored = PlatformMailSettingsResolver::ensureAccounts(PlatformMailSettingsResolver::rawStored());
        $account = PlatformMailSettingsResolver::findAccount($stored, $accountId);
        $account = PlatformMailSettingsResolver::prefillImapFromSmtp($account ?? []);
        $accountId = (string) ($account['id'] ?? '');
        $password = $account['imap_password'] ?? $account['smtp_password'] ?? null;
        $username = trim((string) ($account['imap_username'] ?? ''))
            ?: trim((string) ($account['smtp_username'] ?? ''));
        [$mailbox] = $this->imapMailboxPath($account);

        $inbox = @imap_open($mailbox, $username, (string) $password);
        if (! $inbox) {
            $err = (function_exists('imap_last_error') ? imap_last_error() : null) ?: 'Could not connect to IMAP server.';
            $this->clearImapErrors();

            return [
                'imported' => 0,
                'skipped' => 0,
                'ok' => false,
                'message' => 'IMAP sync failed: '.$err,
                'detail' => 'Update IMAP credentials under Platform settings → Email delivery and try again.',
            ];
        }

        $imported = 0;
        $skipped = 0;
        try {
            $criteria = $this->imapSearchCriteria($account);
            // Prefer stable UIDs (SE_UID). Sequence numbers drift as messages are deleted.
            $emails = @imap_search($inbox, $criteria, SE_UID) ?: [];
            $this->clearImapErrors();
            // Fallback if Gmail category search is unsupported on this server build.
            if ($emails === [] && str_starts_with($criteria, 'X-GM-RAW')) {
                $emails = @imap_search($inbox, 'ALL', SE_UID) ?: [];
                $this->clearImapErrors();
            }
            // Legacy servers that ignore SE_UID still return sequence numbers — flag as such.
            $usingUids = $emails !== [];
            if ($emails === []) {
                $emails = @imap_search($inbox, $criteria) ?: [];
                $this->clearImapErrors();
                if ($emails === [] && str_starts_with($criteria, 'X-GM-RAW')) {
                    $emails = @imap_search($inbox, 'ALL') ?: [];
                    $this->clearImapErrors();
                }
                $usingUids = false;
            }
            rsort($emails);
            $emails = array_slice($emails, 0, max(1, min($limit, 100)));
            $ftUid = $usingUids ? FT_UID : 0;

            foreach ($emails as $uid) {
                $overview = imap_fetch_overview($inbox, (string) $uid, $ftUid);
                $header = $overview[0] ?? null;
                if (! $header) {
                    $skipped++;
                    continue;
                }

                $messageId = isset($header->message_id) ? trim((string) $header->message_id) : null;
                $imapUid = (string) $uid;
                if ($messageId && PlatformMailMessage::query()
                    ->where('message_id', $messageId)
                    ->where(function ($q) use ($accountId) {
                        $q->where('mailbox_account_id', $accountId)->orWhereNull('mailbox_account_id');
                    })
                    ->exists()) {
                    $skipped++;
                    continue;
                }
                if (PlatformMailMessage::query()
                    ->where('imap_uid', $imapUid)
                    ->where('folder', 'inbox')
                    ->where('mailbox_account_id', $accountId)
                    ->exists()) {
                    $skipped++;
                    continue;
                }

                $structure = imap_fetchstructure($inbox, $uid, $ftUid);
                $fullBody = $this->getBody($inbox, (int) $uid, $structure, $usingUids);
                $snippet = $this->snippetBody($fullBody);
                $from = $this->parseAddress($header->from ?? '');
                $to = $this->parseAddressList($header->to ?? '');
                $inReplyTo = isset($header->in_reply_to) ? trim((string) $header->in_reply_to) : null;
                $threadKey = $inReplyTo
                    ?? ($messageId ?: ('imap-'.$imapUid));

                PlatformMailMessage::query()->create([
                    'direction' => 'inbound',
                    'folder' => 'inbox',
                    'mailbox_account_id' => $accountId !== '' ? $accountId : null,
                    'thread_key' => $threadKey,
                    'message_id' => $messageId ?: ('imap-'.$accountId.'-'.$imapUid.'@local'),
                    'in_reply_to' => $inReplyTo,
                    'from_address' => $from['email'] ?: 'unknown@unknown',
                    'from_name' => $from['name'],
                    'to_addresses' => $to,
                    'subject' => isset($header->subject) ? $this->decodeMime((string) $header->subject) : null,
                    // Hybrid: keep a short snippet locally; full body is loaded from IMAP on open.
                    'body_text' => $snippet,
                    'imap_uid' => $imapUid,
                    'received_at' => isset($header->date) ? Carbon::parse($header->date) : now(),
                    'read_at' => null,
                    'meta' => [
                        'body_storage' => 'imap_snippet',
                        'body_snippet_len' => mb_strlen($snippet),
                        'imap_uid_mode' => $usingUids ? 'uid' : 'sequence',
                    ],
                ]);
                $imported++;
            }
        } finally {
            $this->clearImapErrors();
            @imap_close($inbox);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'ok' => true,
            'message' => "Synced inbox: {$imported} new, {$skipped} skipped.",
        ];
    }

    /**
     * Load the full plain-text body from IMAP for a hybrid/snippet inbound message.
     *
     * @return array{ok: bool, body: ?string, message: string}
     */
    public function fetchRemoteBody(PlatformMailMessage $message): array
    {
        if ($message->direction !== 'inbound') {
            return [
                'ok' => true,
                'body' => (string) ($message->body_text ?? ''),
                'message' => 'Not an inbound IMAP message.',
            ];
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        if (($meta['body_storage'] ?? '') !== 'imap_snippet' && trim((string) ($message->imap_uid ?? '')) === '') {
            return [
                'ok' => true,
                'body' => (string) ($message->body_text ?? ''),
                'message' => 'Full body already available locally.',
            ];
        }

        if (! extension_loaded('imap')) {
            return [
                'ok' => false,
                'body' => (string) ($message->body_text ?? ''),
                'message' => 'PHP IMAP extension is not installed.',
            ];
        }

        $accountId = $message->mailbox_account_id ? (string) $message->mailbox_account_id : null;
        $stored = PlatformMailSettingsResolver::ensureAccounts(PlatformMailSettingsResolver::rawStored());
        $account = PlatformMailSettingsResolver::findAccount($stored, $accountId);
        $account = PlatformMailSettingsResolver::prefillImapFromSmtp($account ?? []);

        if (empty($account['imap_enabled']) || empty($account['imap_host'])) {
            return [
                'ok' => false,
                'body' => (string) ($message->body_text ?? ''),
                'message' => 'IMAP is not configured for this account.',
            ];
        }

        $password = $account['imap_password'] ?? $account['smtp_password'] ?? null;
        $username = trim((string) ($account['imap_username'] ?? ''))
            ?: trim((string) ($account['smtp_username'] ?? ''));
        [$mailbox] = $this->imapMailboxPath($account);

        $inbox = @imap_open($mailbox, $username, (string) $password);
        if (! $inbox) {
            $err = (function_exists('imap_last_error') ? imap_last_error() : null) ?: 'unknown error';
            $this->clearImapErrors();
            return [
                'ok' => false,
                'body' => (string) ($message->body_text ?? ''),
                'message' => 'Could not connect to IMAP: '.$err,
            ];
        }

        try {
            $meta = is_array($message->meta) ? $message->meta : [];
            $preferUid = ($meta['imap_uid_mode'] ?? '') === 'uid';
            $seq = null;
            $useUid = false;

            // Prefer stored UID/sequence — HEADER Message-ID search is unsupported on some
            // IMAP servers and can leave a deferred ErrorException on request shutdown.
            if (trim((string) ($message->imap_uid ?? '')) !== '') {
                $candidate = (int) $message->imap_uid;
                foreach ($preferUid ? [true, false] : [false, true] as $tryUid) {
                    $probe = @imap_fetchstructure($inbox, $candidate, $tryUid ? FT_UID : 0);
                    $this->clearImapErrors();
                    if ($probe) {
                        $seq = $candidate;
                        $useUid = $tryUid;
                        break;
                    }
                }
            }

            $messageId = trim((string) ($message->message_id ?? ''));
            if ($seq === null && $messageId !== '' && ! str_ends_with($messageId, '@local')) {
                $found = $this->imapSearchByMessageId($inbox, $messageId);
                if ($found !== []) {
                    $seq = (int) $found[0];
                    $useUid = false;
                }
            }

            if ($seq === null) {
                return [
                    'ok' => false,
                    'body' => (string) ($message->body_text ?? ''),
                    'message' => 'Could not locate the message on the IMAP server.',
                ];
            }

            $structure = @imap_fetchstructure($inbox, $seq, $useUid ? FT_UID : 0);
            $this->clearImapErrors();
            $body = $this->getBody($inbox, $seq, $structure, $useUid);

            return [
                'ok' => $body !== '',
                'body' => $body !== '' ? $body : (string) ($message->body_text ?? ''),
                'message' => $body !== ''
                    ? 'Loaded full body from IMAP.'
                    : 'IMAP message found but body parts were empty.',
            ];
        } finally {
            $this->clearImapErrors();
            @imap_close($inbox);
        }
    }

    /**
     * Delete local mailbox copies older than the retention window (default 3 months).
     * Does not delete mail from the remote IMAP account.
     *
     * @return array{deleted: int, cutoff: string}
     */
    public function pruneLocalMessages(int $months = 3): array
    {
        $months = max(1, min(24, $months));
        $cutoff = now()->subMonths($months);

        $deleted = PlatformMailMessage::query()
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('received_at')->where('received_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('received_at')
                        ->whereNotNull('sent_at')
                        ->where('sent_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('received_at')
                        ->whereNull('sent_at')
                        ->where('created_at', '<', $cutoff);
                });
            })
            ->delete();

        return [
            'deleted' => (int) $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    public function snippetBody(?string $text, int $max = 500): string
    {
        $raw = $this->ensureUtf8((string) $text);
        $clean = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
        if ($clean === '') {
            $clean = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        }
        if ($clean === '') {
            return '';
        }

        return \Illuminate\Support\Str::limit($clean, $max, '…');
    }

    /**
     * Delete an inbound message from the remote IMAP mailbox when possible.
     *
     * @return array{ok: bool, remote: bool, message: string}
     */
    public function deleteRemoteMessage(PlatformMailMessage $message): array
    {
        $imapUid = trim((string) ($message->imap_uid ?? ''));
        if ($message->direction !== 'inbound' || $imapUid === '') {
            return [
                'ok' => true,
                'remote' => false,
                'message' => 'No remote IMAP message to delete.',
            ];
        }

        if (! extension_loaded('imap')) {
            return [
                'ok' => false,
                'remote' => false,
                'message' => 'PHP IMAP extension is not installed; deleted locally only.',
            ];
        }

        $accountId = $message->mailbox_account_id ? (string) $message->mailbox_account_id : null;
        $stored = PlatformMailSettingsResolver::ensureAccounts(PlatformMailSettingsResolver::rawStored());
        $account = PlatformMailSettingsResolver::findAccount($stored, $accountId);
        $account = PlatformMailSettingsResolver::prefillImapFromSmtp($account ?? []);

        if (empty($account['imap_enabled']) || empty($account['imap_host'])) {
            return [
                'ok' => true,
                'remote' => false,
                'message' => 'IMAP is not configured for this account; deleted locally only.',
            ];
        }

        $password = $account['imap_password'] ?? $account['smtp_password'] ?? null;
        $username = trim((string) ($account['imap_username'] ?? ''))
            ?: trim((string) ($account['smtp_username'] ?? ''));
        [$mailbox] = $this->imapMailboxPath($account);

        $inbox = @imap_open($mailbox, $username, (string) $password);
        if (! $inbox) {
            $err = (function_exists('imap_last_error') ? imap_last_error() : null) ?: 'unknown error';
            $this->clearImapErrors();
            return [
                'ok' => false,
                'remote' => false,
                'message' => 'Could not connect to IMAP to delete remotely: '.$err,
            ];
        }

        try {
            $deleted = false;
            $meta = is_array($message->meta) ? $message->meta : [];
            $preferUid = ($meta['imap_uid_mode'] ?? '') === 'uid';

            // Prefer stored UID/sequence first — HEADER search is not supported on every IMAP host.
            if ($imapUid !== '') {
                foreach ($preferUid ? [true, false] : [false, true] as $tryUid) {
                    if (@imap_delete($inbox, $imapUid, $tryUid ? FT_UID : 0)) {
                        $this->clearImapErrors();
                        $deleted = true;
                        break;
                    }
                    $this->clearImapErrors();
                }
            }

            $messageId = trim((string) ($message->message_id ?? ''));
            if (! $deleted && $messageId !== '' && ! str_ends_with($messageId, '@local')) {
                $found = $this->imapSearchByMessageId($inbox, $messageId);
                foreach ($found as $seq) {
                    if (@imap_delete($inbox, (string) $seq)) {
                        $deleted = true;
                    }
                }
                $this->clearImapErrors();
            }

            if ($deleted) {
                @imap_expunge($inbox);
                $this->clearImapErrors();
            }

            return [
                'ok' => (bool) $deleted,
                'remote' => (bool) $deleted,
                'message' => $deleted
                    ? 'Deleted from the email account.'
                    : ('Local delete succeeded; remote delete failed: '.(imap_last_error() ?: 'unknown error')),
            ];
        } finally {
            $this->clearImapErrors();
            @imap_close($inbox);
        }
    }

    /** Drain PHP IMAP error/alert queues so failed searches cannot 500 on request shutdown. */
    protected function clearImapErrors(): void
    {
        if (! function_exists('imap_errors')) {
            return;
        }
        @imap_errors();
        @imap_alerts();
    }

    /**
     * Locate a message by Message-ID when the IMAP server supports HEADER search.
     * Always clears IMAP errors — some hosts reject HEADER and queue a deferred warning.
     *
     * @return list<int|string>
     */
    protected function imapSearchByMessageId($inbox, string $messageId): array
    {
        $needle = trim($messageId, " \t<>");
        if ($needle === '' || ! $inbox) {
            return [];
        }

        $this->clearImapErrors();

        $criteriaList = [
            'HEADER Message-ID "'.$needle.'"',
            'HEADER Message-ID "<'.$needle.'>"',
            'HEADER Message-ID '.$needle,
            'HEADER Message-ID <'.$needle.'>',
        ];

        foreach ($criteriaList as $criteria) {
            $found = @imap_search($inbox, $criteria);
            $errors = function_exists('imap_errors') ? (@imap_errors() ?: []) : [];
            @imap_alerts();

            if ($this->imapErrorsIndicateUnsupportedHeaderSearch($errors)) {
                // Do not retry other HEADER variants on this connection.
                $this->clearImapErrors();

                return [];
            }

            if (is_array($found) && $found !== []) {
                $this->clearImapErrors();

                return array_values($found);
            }
        }

        $this->clearImapErrors();

        return [];
    }

    /**
     * @param  list<string>|array<int, string>  $errors
     */
    protected function imapErrorsIndicateUnsupportedHeaderSearch(array $errors): bool
    {
        foreach ($errors as $err) {
            $text = (string) $err;
            if (stripos($text, 'Unknown search criterion: HEADER') !== false) {
                return true;
            }
            if (stripos($text, 'Unknown search criterion') !== false && stripos($text, 'HEADER') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build IMAP SEARCH criteria. Gmail Primary/Updates are categories inside INBOX, not folders.
     *
     * @param  array<string, mixed>  $account
     */
    protected function imapSearchCriteria(array $account): string
    {
        $host = strtolower(trim((string) ($account['imap_host'] ?? '')));
        $isGmail = str_contains($host, 'gmail.com') || str_contains($host, 'googlemail.com');
        $filter = strtolower(trim((string) ($account['imap_sync_filter'] ?? 'primary')));

        if (! $isGmail) {
            return 'ALL';
        }

        return match ($filter) {
            'updates' => 'X-GM-RAW "category:updates"',
            'all' => 'ALL',
            default => 'X-GM-RAW "category:primary"',
        };
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array{0: string, 1: string}
     */
    protected function imapMailboxPath(array $account): array
    {
        $encryption = $account['imap_encryption'] ?? 'ssl';
        $port = (int) ($account['imap_port'] ?? ($encryption === 'ssl' ? 993 : 143));
        $mailboxName = trim((string) ($account['imap_mailbox'] ?? 'INBOX')) ?: 'INBOX';
        // Never treat Gmail tabs as IMAP folder names.
        if (preg_match('/^(updates|primary|social|promotions|forums)$/i', $mailboxName)
            || preg_match('/\[Gmail\]\/(Updates|Primary|Social|Promotions|Forums)/i', $mailboxName)) {
            $mailboxName = 'INBOX';
        }
        $flags = '/imap';
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        }
        $path = '{'.$account['imap_host'].':'.$port.$flags.'}'.$mailboxName;

        return [$path, $mailboxName];
    }

    /** @return array{email: string, name: ?string} */
    protected function parseAddress(string $raw): array
    {
        if (preg_match('/^(?:"?([^"]*)"?\s)?<?([^>]+)>?$/', trim($raw), $m)) {
            return [
                'name' => trim($m[1] ?? '') ?: null,
                'email' => trim($m[2] ?? $raw),
            ];
        }

        return ['name' => null, 'email' => trim($raw)];
    }

    /** @return list<string> */
    protected function parseAddressList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/,/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $parsed = $this->parseAddress($part);
            if ($parsed['email'] !== '') {
                $out[] = $parsed['email'];
            }
        }

        return $out;
    }

    protected function decodeMime(string $value): string
    {
        $decoded = @imap_mime_header_decode($value);
        if (! is_array($decoded)) {
            return $value;
        }
        $out = '';
        foreach ($decoded as $part) {
            $out .= $part->text ?? '';
        }

        return $out !== '' ? $out : $value;
    }

    protected function getBody($inbox, int $msgNo, $structure, bool $useUid = false): string
    {
        if (! $structure) {
            $raw = (string) @imap_body($inbox, $msgNo, $useUid ? FT_UID : 0);

            return $this->stripHtml($this->ensureUtf8($raw));
        }

        $parts = $this->collectTextParts($inbox, $msgNo, $structure, '', $useUid);
        if (($parts['plain'] ?? '') !== '') {
            return $this->ensureUtf8($parts['plain']);
        }
        if (($parts['html'] ?? '') !== '') {
            return $this->stripHtml($this->ensureUtf8($parts['html']));
        }

        $raw = (string) @imap_body($inbox, $msgNo, $useUid ? FT_UID : 0);

        return $this->stripHtml($this->ensureUtf8($raw));
    }

    /**
     * Recursively collect text/plain and text/html from nested MIME parts.
     *
     * @return array{plain: string, html: string}
     */
    protected function collectTextParts($inbox, int $msgNo, $structure, string $prefix, bool $useUid): array
    {
        $plain = '';
        $html = '';

        if (empty($structure->parts)) {
            $section = $prefix !== '' ? $prefix : '1';
            $body = $this->fetchDecodedPart($inbox, $msgNo, $section, $structure, $useUid);
            $subtype = strtoupper((string) ($structure->subtype ?? 'PLAIN'));
            if ($subtype === 'HTML') {
                $html = $body;
            } else {
                $plain = $body;
            }

            return ['plain' => $plain, 'html' => $html];
        }

        foreach ($structure->parts as $index => $part) {
            $section = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            $type = (int) ($part->type ?? -1);
            $subtype = strtoupper((string) ($part->subtype ?? ''));

            // Multipart — recurse into children (paths like 1.1 / 1.2).
            if ($type === 1 || ! empty($part->parts)) {
                $nested = $this->collectTextParts($inbox, $msgNo, $part, $section, $useUid);
                if ($plain === '' && $nested['plain'] !== '') {
                    $plain = $nested['plain'];
                }
                if ($html === '' && $nested['html'] !== '') {
                    $html = $nested['html'];
                }
                continue;
            }

            if ($type !== 0) {
                continue;
            }

            $body = $this->fetchDecodedPart($inbox, $msgNo, $section, $part, $useUid);
            if ($subtype === 'PLAIN' && $plain === '') {
                $plain = $body;
            } elseif ($subtype === 'HTML' && $html === '') {
                $html = $body;
            }
        }

        return ['plain' => $plain, 'html' => $html];
    }

    protected function fetchDecodedPart($inbox, int $msgNo, string $section, $part, bool $useUid): string
    {
        $flags = ($useUid ? FT_UID : 0) | FT_PEEK;
        $body = (string) @imap_fetchbody($inbox, $msgNo, $section, $flags);
        $encoding = (int) ($part->encoding ?? 0);
        if ($encoding === 3) {
            $decoded = base64_decode($body, true);
            $body = $decoded !== false ? $decoded : $body;
        } elseif ($encoding === 4) {
                    $body = quoted_printable_decode($body);
                }

        return $this->decodePartCharset($body, $part);
    }

    protected function decodePartCharset(string $text, $part): string
    {
        $charset = null;
        foreach ($this->imapPartParameterList($part) as $param) {
            if (strtoupper((string) ($param->attribute ?? '')) === 'CHARSET') {
                $charset = (string) ($param->value ?? '');
                break;
            }
        }

        if ($charset && strtoupper($charset) !== 'UTF-8' && strtoupper($charset) !== 'UTF8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $this->ensureUtf8($text);
    }

    /**
     * IMAP structure params may be an array of objects, a single stdClass, or null.
     *
     * @return list<object>
     */
    protected function imapPartParameterList($part): array
    {
        $params = $part->parameters ?? null;
        $dparams = $part->dparameters ?? null;

        return array_values(array_filter([
            ...$this->normalizeImapParameterCollection($params),
            ...$this->normalizeImapParameterCollection($dparams),
        ]));
    }

    /**
     * @return list<object>
     */
    protected function normalizeImapParameterCollection(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($row) => is_object($row)));
        }
        if (is_object($value)) {
            // Single parameter object (common for simple text parts).
            if (isset($value->attribute) || isset($value->value)) {
                return [$value];
            }
            // Iterable list-like object of parameters.
            $rows = [];
            foreach ((array) $value as $row) {
                if (is_object($row)) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return [];
    }

    protected function ensureUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return is_string($converted) ? $converted : $text;
    }

    protected function stripHtml(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    protected function reportMailFailure(
        string $message,
        string $to,
        string $subject,
        string $kind,
        ?User $user,
        ?\Throwable $exception = null,
    ): void {
        try {
            $reporter = app(\App\Services\SystemIssues\SystemIssueReporter::class);
            $detail = $exception
                ? $reporter->formatException($exception)
                : $message;
            $reporter->reportMessage(
                'Outbound email failed: '.mb_substr($message, 0, 220),
                $detail,
                organizationId: $user?->organization_id ? (int) $user->organization_id : null,
                userId: $user?->id ? (int) $user->id : null,
                context: [
                    'source' => 'mail',
                    'kind' => $kind !== '' ? $kind : 'outbound',
                    'to' => $to,
                    'subject' => mb_substr($subject, 0, 180),
                    'exception_class' => $exception ? $exception::class : null,
                ],
                apiPath: '/platform/mail/send',
                httpMethod: 'POST',
                httpStatus: 500,
                actor: $user,
            );
        } catch (\Throwable) {
            // Never block sending/error handling on issue logging.
        }
    }
}
