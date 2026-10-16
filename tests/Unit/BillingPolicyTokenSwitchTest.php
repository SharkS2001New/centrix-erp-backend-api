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

    public function test_unpaid_login_defaults_on_and_can_be_turned_off(): void
    {
        $policy = new BillingPolicy;

        $this->assertTrue($policy->normalize([])['allow_unpaid_login']);
        $this->assertTrue($policy->allowsUnpaidLoginFrom([]));
        $this->assertFalse($policy->normalize(['allow_unpaid_login' => false])['allow_unpaid_login']);
        $this->assertFalse($policy->allowsUnpaidLoginFrom(['allow_unpaid_login' => false]));
    }

    public function test_missing_and_expired_licences_need_payment_while_trial_does_not(): void
    {
        $licenses = new OrganizationLicenseService;

        $this->assertTrue($licenses->needsPayment(['status' => 'missing']));
        $this->assertTrue($licenses->needsPayment(['status' => 'expired', 'days_remaining' => -1]));
        $this->assertFalse($licenses->needsPayment([
            'status' => 'trialing',
            'is_trial' => true,
            'days_remaining' => 5,
        ]));
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
