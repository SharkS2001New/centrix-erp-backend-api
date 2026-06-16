<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\NotificationSettingsResolver;
use App\Services\Notifications\OrganizationMailSender;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationMailSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_notifications_settings_are_scoped_to_authenticated_organization(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $org = Organization::findOrFail($admin->organization_id);

        $this->patchJson('/api/v1/erp/settings/notifications', [
            'email_enabled' => true,
            'email_from_name' => 'Acme Retail',
            'email_from_address' => 'noreply@acme.test',
        ])
            ->assertOk()
            ->assertJsonPath('organization_id', $org->id)
            ->assertJsonPath('notifications.email_from_name', 'Acme Retail')
            ->assertJsonPath('notifications.email_from_address', 'noreply@acme.test')
            ->assertJsonPath('mail_from.address', 'noreply@acme.test');

        $org->refresh();
        $this->assertSame('Acme Retail', $org->module_settings['notifications']['email_from_name']);
    }

    public function test_mail_from_falls_back_to_company_profile(): void
    {
        $org = Organization::query()->firstOrFail();
        $org->update([
            'org_name' => 'Fallback Co',
            'org_email' => 'hello@fallback.test',
            'module_settings' => [
                'notifications' => [
                    'email_from_name' => '',
                    'email_from_address' => '',
                ],
            ],
        ]);

        $from = NotificationSettingsResolver::mailFrom($org->fresh());

        $this->assertSame('Fallback Co', $from['name']);
        $this->assertSame('hello@fallback.test', $from['address']);
    }

    public function test_organization_smtp_settings_are_stored(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/erp/settings/notifications', [
            'email_enabled' => true,
            'smtp_enabled' => true,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_username' => 'mailer',
            'smtp_password' => 'secret-pass',
            'email_from_address' => 'noreply@acme.test',
        ])
            ->assertOk()
            ->assertJsonPath('notifications.smtp_host', 'smtp.example.test')
            ->assertJsonPath('notifications_status.uses_organization_smtp', true);
    }

    public function test_organization_mail_sender_uses_org_from_when_notifications_enabled(): void
    {
        $org = Organization::query()->firstOrFail();
        $org->update([
            'module_settings' => [
                'notifications' => [
                    'email_enabled' => true,
                    'email_from_name' => 'Org Mailer',
                    'email_from_address' => 'mailer@org.test',
                ],
            ],
        ]);

        $sender = app(OrganizationMailSender::class);

        $this->assertTrue($sender->canSendForOrganization($org->fresh(), requireNotificationsEnabled: true));
        $this->assertSame('mailer@org.test', $sender->resolveFrom($org->fresh())['address']);
    }
}
