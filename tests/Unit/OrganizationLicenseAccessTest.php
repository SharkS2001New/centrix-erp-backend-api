<?php

namespace Tests\Unit;

use App\Services\Platform\OrganizationLicenseService;
use Tests\TestCase;

class OrganizationLicenseAccessTest extends TestCase
{
    public function test_access_mode_separates_paid_free_and_expired(): void
    {
        $licenses = app(OrganizationLicenseService::class);

        $this->assertSame('full', $licenses->accessMode([
            'status' => 'active',
            'days_remaining' => 20,
            'is_trial' => false,
        ]));
        $this->assertSame('free', $licenses->accessMode([
            'status' => 'missing',
        ]));
        $this->assertSame('trial', $licenses->accessMode([
            'status' => 'trialing',
            'is_trial' => true,
            'days_remaining' => 5,
        ]));
        $this->assertSame('expired', $licenses->accessMode([
            'status' => 'expired',
            'is_trial' => true,
            'days_remaining' => -1,
        ]));
        $this->assertSame('expired', $licenses->accessMode([
            'status' => 'active',
            'days_remaining' => -1,
        ]));
        $this->assertSame('token', $licenses->accessMode([
            'status' => 'expired',
            'days_remaining' => -3,
            'token_balance' => 500,
        ]));
        $this->assertFalse($licenses->needsPayment([
            'status' => 'trialing',
            'is_trial' => true,
            'days_remaining' => 5,
        ]));
        $this->assertFalse($licenses->usesPackageOne([
            'status' => 'trialing',
            'is_trial' => true,
            'days_remaining' => 5,
        ]));
        $this->assertTrue($licenses->usesPackageOne([
            'status' => 'missing',
        ]));
        $this->assertFalse($licenses->usesPackageOne([
            'status' => 'active',
            'days_remaining' => 20,
            'is_trial' => false,
        ]));
        // blocksUnpaidAccess follows BillingPolicy; with default allow_unpaid_login it stays open.
        $this->assertFalse($licenses->blocksUnpaidAccess([
            'status' => 'missing',
        ]));
        $this->assertTrue($licenses->needsPayment([
            'status' => 'expired',
            'is_trial' => true,
            'days_remaining' => -1,
        ]));
    }

    public function test_external_pos_requires_a_dated_subscription_not_tokens(): void
    {
        $licenses = app(OrganizationLicenseService::class);

        $this->assertFalse($licenses->externalPosAllowed([
            'status' => 'expired',
            'days_remaining' => -3,
            'token_balance' => 500,
        ]));
        $this->assertTrue($licenses->externalPosAllowed([
            'status' => 'active',
            'days_remaining' => 20,
            'is_trial' => false,
            'token_balance' => 500,
        ]));
        $this->assertTrue($licenses->externalPosAllowed([
            'status' => 'trialing',
            'is_trial' => true,
            'days_remaining' => 5,
        ]));
    }

    public function test_limited_routes_allow_catalogue_and_sales_reports_only(): void
    {
        $licenses = app(OrganizationLicenseService::class);

        $this->assertTrue($licenses->allowsLimitedRoute('api/v1/products'));
        $this->assertTrue($licenses->allowsLimitedRoute('api/v1/customers/15'));
        $this->assertTrue($licenses->allowsLimitedRoute('api/v1/suppliers'));
        $this->assertTrue($licenses->allowsLimitedRoute('api/v1/reports/daily-sales'));
        $this->assertTrue($licenses->allowsLimitedRoute('api/v1/erp/capabilities'));
        $this->assertFalse($licenses->allowsLimitedRoute('api/v1/sales'));
        $this->assertFalse($licenses->allowsLimitedRoute('api/v1/reports/profit-loss'));
        $this->assertFalse($licenses->allowsLimitedRoute('api/v1/payroll/runs'));
    }

    public function test_package_one_opens_the_dashboard_until_more_pages_are_allowed(): void
    {
        $access = app(\App\Services\Platform\FreePlanAccess::class);
        $grant = $access->grantFor('commerce', []);

        $this->assertTrue($access->allowsPath('api/v1/reports/dashboard', $grant));
        $this->assertTrue($access->allowsPath('api/v1/erp/capabilities', $grant));
        $this->assertFalse($access->allowsPath('api/v1/products', $grant));
        $this->assertFalse($access->allowsPath('api/v1/customers', $grant));

        $opened = $access->grantFor('commerce', ['page_keys' => ['dashboard', 'products']]);
        $this->assertTrue($access->allowsPath('api/v1/products', $opened));
        $this->assertSame(['dashboard'], $access->grantFor('spa', [])['page_keys']);

        $fromFeatures = $access->grantFor('commerce', [
            'feature_keys' => ['backoffice' => ['catalogue.products']],
        ]);
        $this->assertContains('products', $fromFeatures['page_keys']);
        $this->assertNotContains('customers', $fromFeatures['page_keys']);
    }

    public function test_yearly_cycle_and_org_renewal_amount(): void
    {
        $licenses = app(OrganizationLicenseService::class);
        $plan = new \App\Models\PlatformPlan(['interval' => 'yearly', 'yearly_price' => 48000]);
        $subscription = new \App\Models\PlatformSubscription(['renewal_price' => 51000]);
        $subscription->setRelation('plan', $plan);

        $this->assertSame('yearly', $licenses->billingCycle($subscription));
        $this->assertSame(51000.0, $licenses->renewalAmount($subscription));

        $catalog = new \App\Models\PlatformSubscription();
        $catalog->setRelation('plan', $plan);
        $this->assertSame(48000.0, $licenses->renewalAmount($catalog));
        $this->assertSame('monthly', $licenses->billingCycleForInterval('monthly'));
    }
}
