<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Platform\PlatformInvoiceBillingService;
use App\Services\Platform\PlatformInvoiceDocumentService;
use App\Services\Platform\PlatformMailboxService;
use App\Services\Platform\SubscriptionRenewalReminderService;
use Carbon\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SubscriptionRenewalReminderServiceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_parses_reminder_days(): void
    {
        $service = $this->makeService();

        $this->assertSame([7, 14, 30], $service->reminderDays([
            'subscription_reminder_days' => '30, 14,7',
        ]));
        $this->assertSame([0, 3], $service->reminderDays([
            'subscription_reminder_days' => [3, 0, 3],
        ]));
    }

    public function test_collects_org_and_admin_emails(): void
    {
        $org = Organization::query()
            ->where('company_code', '!=', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();

        $org->forceFill(['org_email' => 'billing@acme.test'])->save();

        $admin = User::query()
            ->where('organization_id', $org->id)
            ->where('is_admin', true)
            ->first();

        if ($admin) {
            $admin->forceFill(['email' => 'admin@acme.test', 'is_active' => true])->save();
        }

        $emails = $this->makeService()->recipientEmails($org->fresh())->all();

        $this->assertContains('billing@acme.test', $emails);
        if ($admin) {
            $this->assertContains('admin@acme.test', $emails);
        }
    }

    public function test_creates_draft_renewal_invoice(): void
    {
        $org = Organization::query()
            ->where('company_code', '!=', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();

        $sub = PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $org->id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addDays(14)->toDateString(),
                'renewal_price' => 15000,
                'amount' => 15000,
                'currency' => 'KES',
            ],
        );
        $sub->forceFill([
            'status' => 'active',
            'current_period_end' => now()->addDays(14)->toDateString(),
            'renewal_price' => 15000,
            'invoice_id' => null,
        ])->save();

        $invoice = $this->makeService()->createDraftRenewalInvoice($sub->fresh(['organization', 'plan']));

        $this->assertSame('draft', $invoice->status);
        $this->assertSame($org->id, $invoice->organization_id);
        $this->assertEquals(15000.0, (float) $invoice->total);
        $this->assertSame($invoice->id, $sub->fresh()->invoice_id);
        $this->assertNotEmpty($invoice->template_id);
    }

    public function test_skips_when_reminders_disabled(): void
    {
        $result = $this->makeService()->processDueReminders(Carbon::today());
        $this->assertSame(0, $result['sent']);
        $this->assertNotEmpty($result['errors']);
    }

    protected function makeService(): SubscriptionRenewalReminderService
    {
        return new SubscriptionRenewalReminderService(
            app(PlatformInvoiceBillingService::class),
            app(PlatformInvoiceDocumentService::class),
            app(PlatformMailboxService::class),
        );
    }
}
