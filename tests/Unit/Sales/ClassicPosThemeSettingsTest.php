<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\ClassicPosThemeSettings;
use PHPUnit\Framework\TestCase;

class ClassicPosThemeSettingsTest extends TestCase
{
    public function test_normalize_theme_template_defaults_to_legacy(): void
    {
        $this->assertSame('legacy', ClassicPosThemeSettings::normalizeThemeTemplate(null));
        $this->assertSame('legacy', ClassicPosThemeSettings::normalizeThemeTemplate('invalid'));
        $this->assertSame('centrix', ClassicPosThemeSettings::normalizeThemeTemplate('centrix'));
        $this->assertSame('legacy', ClassicPosThemeSettings::normalizeThemeTemplate('default'));
    }
}
