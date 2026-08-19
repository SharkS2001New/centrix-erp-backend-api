<?php

namespace Tests\Unit;

use App\Services\Platform\PlatformMailSettingsResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlatformMailSmtpOnlyModeTest extends TestCase
{
    #[Test]
    public function sanitize_account_marks_smtp_only_when_imap_disabled(): void
    {
        $account = PlatformMailSettingsResolver::sanitizeAccount([
            'enabled' => true,
            'from_address' => 'billing@example.com',
            'smtp_host' => 'smtp.example.com',
            'smtp_password' => 'secret',
            'imap_enabled' => false,
        ]);

        $this->assertTrue($account['outbound_ready']);
        $this->assertFalse($account['inbox_sync_ready']);
        $this->assertSame('smtp_only', $account['mail_mode']);
    }

    #[Test]
    public function sanitize_account_marks_smtp_and_imap_when_sync_configured(): void
    {
        $account = PlatformMailSettingsResolver::sanitizeAccount([
            'enabled' => true,
            'from_address' => 'billing@example.com',
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => 'billing@example.com',
            'smtp_password' => 'secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.example.com',
            'imap_username' => 'billing@example.com',
        ]);

        $this->assertTrue($account['outbound_ready']);
        $this->assertSame('smtp_and_imap', $account['mail_mode']);
        if (extension_loaded('imap')) {
            $this->assertTrue($account['inbox_sync_ready']);
        } else {
            $this->assertFalse($account['inbox_sync_ready']);
        }
    }

    #[Test]
    public function ensure_accounts_keeps_an_explicit_empty_list(): void
    {
        $stored = PlatformMailSettingsResolver::ensureAccounts([
            'accounts' => [],
            'imap_host' => 'imap.gmail.com',
            'imap_enabled' => true,
        ]);

        $this->assertSame([], $stored['accounts']);
        $this->assertNull($stored['active_account_id']);
    }

    #[Test]
    public function ensure_accounts_migrates_legacy_settings_when_accounts_key_is_missing(): void
    {
        $stored = PlatformMailSettingsResolver::ensureAccounts([
            'from_address' => 'legacy@example.com',
            'smtp_host' => 'smtp.example.com',
        ]);

        $this->assertCount(1, $stored['accounts']);
        $this->assertSame('legacy@example.com', $stored['accounts'][0]['from_address']);
        $this->assertSame('smtp.example.com', $stored['accounts'][0]['smtp_host']);
    }
}
