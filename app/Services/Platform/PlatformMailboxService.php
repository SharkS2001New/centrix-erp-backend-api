<?php

namespace App\Services\Platform;

use App\Models\PlatformMailMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

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
    ): PlatformMailMessage {
        $kind = (string) ($meta['kind'] ?? '');
        $isAuthMail = (bool) ($meta['no_reply'] ?? false)
            || in_array($kind, ['two_factor', 'email_verification'], true);

        $settings = $isAuthMail
            ? PlatformMailSettingsResolver::resolveForAuth()
            : PlatformMailSettingsResolver::resolve();

        $messageIdBody = Str::uuid()->toString().'@centrix.platform';
        $messageId = '<'.$messageIdBody.'>';
        $threadKey = $replyTo?->thread_key
            ?? $replyTo?->message_id
            ?? $messageId;

        $profile = ($isAuthMail && ($settings['auth_profile'] ?? '') === 'auth') ? 'auth' : 'default';
        PlatformMailSettingsResolver::applyMailConfig($profile);

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
            $main = PlatformMailSettingsResolver::resolve();
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
        ) {
            $message->to($to)->subject($subject);
            if ($fromAddress !== '') {
                $message->from($fromAddress, $fromName !== '' ? $fromName : null);
            }
            $headers = $message->getHeaders();
            // Symfony requires IdentificationHeader for Message-ID / In-Reply-To / References
            // (addTextHeader creates UnstructuredHeader and throws).
            if ($headers->has('Message-ID')) {
                $headers->remove('Message-ID');
            }
            $headers->addIdHeader('Message-ID', $messageIdBody);
            if ($inReplyTo) {
                $replyId = trim((string) $inReplyTo, " \t<>");
                if ($replyId !== '') {
                    if ($headers->has('In-Reply-To')) {
                        $headers->remove('In-Reply-To');
                    }
                    if ($headers->has('References')) {
                        $headers->remove('References');
                    }
                    $headers->addIdHeader('In-Reply-To', $replyId);
                    $headers->addIdHeader('References', $replyId);
                }
            }
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

        return PlatformMailMessage::query()->create([
            'direction' => 'outbound',
            'folder' => 'sent',
            'thread_key' => $threadKey,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'from_address' => $fromAddress !== '' ? $fromAddress : ($settings['from_address'] ?? ''),
            'from_name' => $fromName !== '' ? $fromName : ($settings['from_name'] ?? null),
            'to_addresses' => [$to],
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

    /** @return array{imported: int, skipped: int, message: string} */
    public function syncInbox(int $limit = 50): array
    {
        if (! extension_loaded('imap')) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'message' => 'PHP IMAP extension is not installed. Sent mail still works; install php-imap to sync replies into the mailbox.',
            ];
        }

        $org = PlatformMailSettingsResolver::platformOrganization();
        $stored = is_array($org?->module_settings[PlatformMailSettingsResolver::SETTINGS_KEY] ?? null)
            ? $org->module_settings[PlatformMailSettingsResolver::SETTINGS_KEY]
            : [];
        $settings = array_merge(PlatformMailSettingsResolver::defaults(), $stored);

        if (empty($settings['imap_enabled']) || empty($settings['imap_host'])) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'message' => 'IMAP is not configured. Add host/credentials under Settings → Email delivery, then sync again.',
            ];
        }

        $encryption = $settings['imap_encryption'] ?? 'ssl';
        $port = (int) ($settings['imap_port'] ?? ($encryption === 'ssl' ? 993 : 143));
        $mailboxName = $settings['imap_mailbox'] ?? 'INBOX';
        $flags = '/imap';
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        }
        $mailbox = '{'.$settings['imap_host'].':'.$port.$flags.'}'.$mailboxName;

        $password = $stored['imap_password'] ?? $stored['smtp_password'] ?? null;
        $username = $settings['imap_username'] ?: ($settings['smtp_username'] ?? '');
        if (! $password || ! $username) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'message' => 'IMAP username/password missing.',
            ];
        }

        $inbox = @imap_open($mailbox, $username, $password);
        if (! $inbox) {
            $err = imap_last_error() ?: 'Could not connect to IMAP server.';
            abort(422, 'IMAP sync failed: '.$err);
        }

        $imported = 0;
        $skipped = 0;
        try {
            $emails = imap_search($inbox, 'ALL') ?: [];
            rsort($emails);
            $emails = array_slice($emails, 0, max(1, min($limit, 100)));

            foreach ($emails as $uid) {
                $overview = imap_fetch_overview($inbox, (string) $uid, 0);
                $header = $overview[0] ?? null;
                if (! $header) {
                    $skipped++;
                    continue;
                }

                $messageId = isset($header->message_id) ? trim((string) $header->message_id) : null;
                $imapUid = (string) $uid;
                if ($messageId && PlatformMailMessage::query()->where('message_id', $messageId)->exists()) {
                    $skipped++;
                    continue;
                }
                if (PlatformMailMessage::query()->where('imap_uid', $imapUid)->where('folder', 'inbox')->exists()) {
                    $skipped++;
                    continue;
                }

                $structure = imap_fetchstructure($inbox, $uid);
                $body = $this->getBody($inbox, $uid, $structure);
                $from = $this->parseAddress($header->from ?? '');
                $to = $this->parseAddressList($header->to ?? '');
                $inReplyTo = isset($header->in_reply_to) ? trim((string) $header->in_reply_to) : null;
                $threadKey = $inReplyTo
                    ?? ($messageId ?: ('imap-'.$imapUid));

                PlatformMailMessage::query()->create([
                    'direction' => 'inbound',
                    'folder' => 'inbox',
                    'thread_key' => $threadKey,
                    'message_id' => $messageId ?: ('imap-'.$imapUid.'@local'),
                    'in_reply_to' => $inReplyTo,
                    'from_address' => $from['email'] ?: 'unknown@unknown',
                    'from_name' => $from['name'],
                    'to_addresses' => $to,
                    'subject' => isset($header->subject) ? $this->decodeMime((string) $header->subject) : null,
                    'body_text' => $body,
                    'imap_uid' => $imapUid,
                    'received_at' => isset($header->date) ? Carbon::parse($header->date) : now(),
                    'read_at' => null,
                ]);
                $imported++;
            }
        } finally {
            imap_close($inbox);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'message' => "Synced inbox: {$imported} new, {$skipped} skipped.",
        ];
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

    protected function getBody($inbox, int $uid, $structure): string
    {
        if (! $structure) {
            return (string) imap_body($inbox, $uid);
        }

        if (empty($structure->parts)) {
            $body = imap_body($inbox, $uid);
            if (! empty($structure->encoding) && (int) $structure->encoding === 3) {
                $body = base64_decode($body) ?: $body;
            } elseif (! empty($structure->encoding) && (int) $structure->encoding === 4) {
                $body = quoted_printable_decode($body);
            }

            return $this->stripHtml((string) $body);
        }

        foreach ($structure->parts as $index => $part) {
            $type = (int) ($part->type ?? 0);
            $subtype = strtoupper((string) ($part->subtype ?? ''));
            if ($type === 0 && $subtype === 'PLAIN') {
                $body = imap_fetchbody($inbox, $uid, (string) ($index + 1));
                if ((int) ($part->encoding ?? 0) === 3) {
                    $body = base64_decode($body) ?: $body;
                } elseif ((int) ($part->encoding ?? 0) === 4) {
                    $body = quoted_printable_decode($body);
                }

                return (string) $body;
            }
        }

        foreach ($structure->parts as $index => $part) {
            $type = (int) ($part->type ?? 0);
            $subtype = strtoupper((string) ($part->subtype ?? ''));
            if ($type === 0 && $subtype === 'HTML') {
                $body = imap_fetchbody($inbox, $uid, (string) ($index + 1));
                if ((int) ($part->encoding ?? 0) === 3) {
                    $body = base64_decode($body) ?: $body;
                } elseif ((int) ($part->encoding ?? 0) === 4) {
                    $body = quoted_printable_decode($body);
                }

                return $this->stripHtml((string) $body);
            }
        }

        return $this->stripHtml((string) imap_body($inbox, $uid));
    }

    protected function stripHtml(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
