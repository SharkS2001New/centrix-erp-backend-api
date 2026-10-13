<?php

namespace Tests\Unit;

use App\Services\Platform\BillingPolicy;
use App\Services\Platform\OrganizationLicenseService;
use Tests\TestCase;

class BillingPolicyTokenSwitchTest extends TestCase
{
    public function test_a_saved_policy_without_the_switch_keeps_token_billing_on(): void
    {
        $policy = (new BillingPolicy)->normalize([]);

        $this->assertTrue($policy['tokens_enabled']);
    }

    public function test_the_platform_admin_can_turn_token_billing_off(): void
    {
        $policy = (new BillingPolicy)->normalize(['tokens_enabled' => false]);

        $this->assertFalse($policy['tokens_enabled']);
        $this->assertFalse((new BillingPolicy)->tokensEnabledFrom($policy));
    }

    public function test_a_token_balance_does_not_open_the_organisation_when_tokens_are_off(): void
    {
        $licenses = new OrganizationLicenseService;

        $this->assertSame('token', $licenses->accessMode([
            'status' => 'expired',
            'token_balance' => 40,
            'tokens_enabled' => true,
        ]));
        $this->assertSame('expired', $licenses->accessMode([
            'status' => 'expired',
            'token_balance' => 40,
            'tokens_enabled' => false,
        ]));
    }
}
