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
}
