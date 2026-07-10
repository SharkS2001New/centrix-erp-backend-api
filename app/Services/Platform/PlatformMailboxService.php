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
    ): PlatformMailMessage {
        $settings = PlatformMailSettingsResolver::resolve();
        $messageId = '<'.Str::uuid()->toString().'@centrix.platform>';
        $threadKey = $replyTo?->thread_key
            ?? $replyTo?->message_id
            ?? $messageId;

        PlatformMailSettingsResolver::applyMailConfig();
        if (! ($settings['enabled'] ?? false)) {
            abort(422, 'Platform outbound email is disabled. Enable it under Settings → Email delivery.');
        }

        $inReplyTo = $replyTo?->message_id;
        \Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($to, $subject, $settings, $messageId, $inReplyTo) {
            $message->to($to)->subject($subject);
            $message->getHeaders()->addTextHeader('Message-ID', $messageId);
            if ($inReplyTo) {
                $message->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
                $message->getHeaders()->addTextHeader('References', $inReplyTo);
            }
            if (! empty($settings['reply_to'])) {
                $message->replyTo($settings['reply_to']);
            }
        });

        return PlatformMailMessage::query()->create([
            'direction' => 'outbound',
            'folder' => 'sent',
            'thread_key' => $threadKey,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'from_address' => $settings['from_address'] ?? '',
            'from_name' => $settings['from_name'] ?? null,
            'to_addresses' => [$to],
            'subject' => $subject,
            'body_text' => $body,
            'organization_id' => $meta['organization_id'] ?? $replyTo?->organization_id,
            'contract_id' => $meta['contract_id'] ?? null,
            'sent_by_user_id' => $user?->id,
            'read_at' => now(),
            'sent_at' => now(),
            'meta' => $meta ?: null,
        ]);
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
