<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\PosCashRoundingSettings;
use PHPUnit\Framework\TestCase;

class PosCashRoundingSettingsTest extends TestCase
{
    public function test_defaults_on_for_classic_layout_when_org_flag_unset(): void
    {
        $this->assertTrue(PosCashRoundingSettings::enabled(
            ['external_pos_layout' => 'classic'],
            [],
        ));
    }

    public function test_defaults_off_for_modern_layout_when_org_flag_unset(): void
    {
        $this->assertFalse(PosCashRoundingSettings::enabled(
            ['external_pos_layout' => 'modern'],
            [],
        ));
    }

    public function test_honours_explicit_org_flag(): void
    {
        $this->assertTrue(PosCashRoundingSettings::enabled(
            ['external_pos_layout' => 'modern'],
            ['enable_pos_cash_rounding' => true],
        ));

        $this->assertFalse(PosCashRoundingSettings::enabled(
            ['external_pos_layout' => 'classic'],
            ['enable_pos_cash_rounding' => false],
        ));
    }
}
