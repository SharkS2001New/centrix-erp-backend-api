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
        $this->assertTrue($licenses->needsPayment([
            'status' => 'expired',
            'is_trial' => true,
            'days_remaining' => -1,
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
}
