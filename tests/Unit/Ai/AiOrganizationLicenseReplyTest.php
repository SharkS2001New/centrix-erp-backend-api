<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiToolResultReplyBuilder;
use Tests\TestCase;

class AiOrganizationLicenseReplyTest extends TestCase
{
    public function test_fallback_formats_active_license(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'get_organization_license',
                'result' => [
                    'source' => 'organization_license',
                    'is_platform_tenant' => false,
                    'organization' => [
                        'org_name' => 'Demo Retail',
                        'company_code' => 'DEMO',
                    ],
                    'license' => [
                        'status' => 'active',
                        'expires_at' => '2026-10-15',
                        'is_trial' => false,
                        'days_remaining' => 17,
                        'warning_days' => 7,
                        'plan_name' => 'Growth',
                    ],
                    'expired' => false,
                    'about_to_expire' => false,
                    'subscription' => [
                        'seat_count' => 12,
                        'renewal_price' => 45000,
                        'currency' => 'KES',
                    ],
                    'tip' => 'Answer from this licence payload.',
                ],
            ],
        ]);

        $this->assertStringContainsString('Demo Retail', $reply);
        $this->assertStringContainsString('Growth', $reply);
        $this->assertStringContainsString('2026-10-15', $reply);
        $this->assertStringContainsString('17', $reply);
        $this->assertStringNotContainsString('Answer from this licence', $reply);
    }

    public function test_fallback_warns_when_about_to_expire(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'get_organization_license',
                'result' => [
                    'source' => 'organization_license',
                    'organization' => ['org_name' => 'Demo Retail'],
                    'license' => [
                        'status' => 'active',
                        'expires_at' => '2026-10-01',
                        'days_remaining' => 3,
                        'warning_days' => 7,
                        'plan_name' => 'Starter',
                    ],
                    'expired' => false,
                    'about_to_expire' => true,
                ],
            ],
        ]);

        $this->assertStringContainsString('about to expire', $reply);
        $this->assertStringContainsString('3', $reply);
    }
}
