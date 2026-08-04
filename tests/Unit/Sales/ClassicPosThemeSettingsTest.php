<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\ClassicPosThemeSettings;
use PHPUnit\Framework\TestCase;

class ClassicPosThemeSettingsTest extends TestCase
{
    public function test_normalize_theme_template(): void
    {
        $this->assertSame('centrix', ClassicPosThemeSettings::normalizeThemeTemplate(null));
        $this->assertSame('centrix', ClassicPosThemeSettings::normalizeThemeTemplate('invalid'));
        $this->assertSame('centrix', ClassicPosThemeSettings::normalizeThemeTemplate('centrix'));
        $this->assertSame('centrix', ClassicPosThemeSettings::normalizeThemeTemplate('default'));
        $this->assertSame('legacy', ClassicPosThemeSettings::normalizeThemeTemplate('legacy'));
    }

    public function test_normalize_theme_colors(): void
    {
        $this->assertSame([], ClassicPosThemeSettings::normalizeThemeColors(null));
        $this->assertSame([], ClassicPosThemeSettings::normalizeThemeColors(['workspace' => 'nope']));
        $this->assertSame(
            [
                'workspace' => '#cdb48b',
                'header' => '#be185d',
                'button' => '#abcdef',
                'select' => '#7a2031',
            ],
            ClassicPosThemeSettings::normalizeThemeColors([
                'workspace' => 'CDB48B',
                'header' => '#BE185D',
                'footer' => '',
                'button' => '#ABCDEF',
                'select' => '#7A2031',
                'extra' => '#111111',
            ]),
        );
        $this->assertSame(
            ['footer' => '#112233'],
            ClassicPosThemeSettings::normalizeThemeColors(['footer' => '#123']),
        );
    }

    public function test_normalize_bundle(): void
    {
        $normalized = ClassicPosThemeSettings::normalize([
            'classic_pos_theme_template' => 'rose',
            'classic_pos_theme_colors' => ['header' => '#be185d'],
        ]);
        $this->assertSame('rose', $normalized['classic_pos_theme_template']);
        $this->assertSame(['header' => '#be185d'], $normalized['classic_pos_theme_colors']);
    }
}
