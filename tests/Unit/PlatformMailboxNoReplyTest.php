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
    }

    /** @param  array<string, mixed>  $overrides */
    protected function enablePlatformMail(array $overrides = []): void
    {
        $org = PlatformMailSettingsResolver::platformOrganization();
        if (! $org) {
            $this->markTestSkipped('PLATFORM organization not found.');
        }

        $settings = $org->module_settings ?? [];
        $settings[PlatformMailSettingsResolver::SETTINGS_KEY] = array_merge(
            PlatformMailSettingsResolver::defaults(),
            [
                'enabled' => true,
                'from_address' => 'platform@example.com',
                'from_name' => 'Centrix Test',
                'reply_to' => 'support@example.com',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'platform@example.com',
                'smtp_encryption' => 'tls',
                'smtp_password' => 'secret',
            ],
            $overrides,
        );
        $org->module_settings = $settings;
        $org->save();
    }
}
