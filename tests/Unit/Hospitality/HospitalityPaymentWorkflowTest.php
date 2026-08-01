<?php

namespace Tests\Unit\Hospitality;

use App\Services\Hospitality\HospitalityPaymentWorkflow;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HospitalityPaymentWorkflowTest extends TestCase
{
    #[Test]
    public function defaults_enable_all_three_statuses(): void
    {
        $defaults = HospitalityPaymentWorkflow::DEFAULTS;
        $this->assertTrue($defaults['unpaid']);
        $this->assertTrue($defaults['partially_paid']);
        $this->assertTrue($defaults['paid']);
    }

    #[Test]
    public function normalize_keeps_paid_enabled(): void
    {
        $normalized = HospitalityPaymentWorkflow::normalize([
            'unpaid' => false,
            'partially_paid' => false,
            'paid' => false,
        ]);

        $this->assertFalse($normalized['unpaid']);
        $this->assertFalse($normalized['partially_paid']);
        $this->assertTrue($normalized['paid']);
    }
}
