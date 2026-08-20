<?php

namespace Tests\Unit;

use App\Models\PlatformMailMessage;
use App\Models\User;
use App\Services\Platform\PlatformMailSettingsResolver;
use App\Services\Platform\PlatformMailboxService;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformMailboxNoReplyTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_two_factor_mail_skips_reply_to_and_uses_noreply_from(): void
    {
        Mail::fake();
        $this->enablePlatformMail([
            'from_address' => 'billing@example.com',
            'reply_to' => 'support@example.com',
            'noreply_address' => 'noreply@example.com',
        ]);

        $user = User::where('username', 'admin')->firstOrFail();

        app(PlatformMailboxService::class)->send(
            'user@example.com',
            'Centrix ERP — your sign-in verification code',
            "Code: 123456\n\nThis is an automated message — please do not reply.\n",
            $user,
            ['kind' => 'two_factor', 'no_reply' => true],
        );

        $stored = PlatformMailMessage::query()->latest('id')->first();
        $this->assertNotNull($stored);
        $this->assertSame('noreply@example.com', $stored->from_address);
        $this->assertSame('two_factor', $stored->meta['kind'] ?? null);
        $this->assertStringNotContainsString('123456', (string) $stored->body_text);
        $this->assertStringContainsString('******', (string) $stored->body_text);
    }

    public function test_mail_stats_count_two_factor_and_renewal_kinds(): void
    {
        Mail::fake();
        $this->enablePlatformMail();

        $user = User::where('username', 'admin')->firstOrFail();
        $mailbox = app(PlatformMailboxService::class);

        $mailbox->send('a@example.com', '2FA', 'Code: 111111', $user, [
            'kind' => 'two_factor',
            'no_reply' => true,
        ]);
        $mailbox->send('b@example.com', 'Verify', 'Code: 222222', $user, [
            'kind' => 'email_verification',
            'no_reply' => true,
        ]);
        $mailbox->send('c@example.com', 'Renewal', 'Please renew', null, [
            'kind' => 'subscription_renewal_reminder',
            'organization_id' => 1,
        ]);

        $stats = \App\Services\Platform\PlatformMailStats::summarize();
        $this->assertSame(1, $stats['two_factor']['all_time']);
        $this->assertSame(1, $stats['email_verification']['all_time']);
        $this->assertSame(2, $stats['auth_codes']['all_time']);
        $this->assertSame(1, $stats['renewal_reminders']['all_time']);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function enablePlatformMail(array $overrides = []): void
    {
        $org = PlatformMailSettingsResolver::platformOrganization();
        if (! $org) {
            $this->markTestSkipped('PLATFORM organization not found.');
        }

        $account = array_merge(PlatformMailSettingsResolver::accountDefaults(), [
            'id' => 'test-mailbox-1',
            'label' => 'Test',
            'is_default' => true,
            'enabled' => true,
            'from_address' => 'platform@example.com',
            'from_name' => 'Centrix Test',
            'reply_to' => 'support@example.com',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_username' => 'platform@example.com',
            'smtp_encryption' => 'tls',
            'smtp_password' => 'secret',
        ], $overrides);

        $settings = $org->module_settings ?? [];
        $settings[PlatformMailSettingsResolver::SETTINGS_KEY] = array_merge(
            PlatformMailSettingsResolver::defaults(),
            [
                'enabled' => true,
                'accounts' => [$account],
                'active_account_id' => $account['id'],
                'from_address' => $account['from_address'],
                'from_name' => $account['from_name'],
                'reply_to' => $account['reply_to'],
                'noreply_address' => $account['noreply_address'] ?? ($overrides['noreply_address'] ?? ''),
                'smtp_host' => $account['smtp_host'],
                'smtp_port' => $account['smtp_port'],
                'smtp_username' => $account['smtp_username'],
                'smtp_encryption' => $account['smtp_encryption'],
                'smtp_password' => $account['smtp_password'],
            ],
            array_diff_key($overrides, array_flip([
                'from_address', 'from_name', 'reply_to', 'noreply_address',
                'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'smtp_password',
                'enabled', 'label',
            ])),
        );
        $org->module_settings = $settings;
        $org->save();
    }
}
