<?php

namespace Tests\Unit\Print;

use App\Services\Print\OrgDocumentThemeService;
use App\Services\Purchasing\LpoDocumentPdfService;
use Tests\TestCase;

class OrgDocumentThemeServiceTest extends TestCase
{
    public function test_theme_for_matches_erp_safari_accent(): void
    {
        $theme = app(OrgDocumentThemeService::class)->themeFor('safari');
        $this->assertSame('#92400e', $theme['accent']);
        $this->assertSame('solid', $theme['header']);
    }

    public function test_default_falls_back_to_modern_palette(): void
    {
        $theme = app(OrgDocumentThemeService::class)->themeFor('default');
        $this->assertSame('#2563eb', $theme['accent']);
        $this->assertSame('top', $theme['header']);
    }

    public function test_theme_css_includes_accent_and_background(): void
    {
        $service = app(OrgDocumentThemeService::class);
        $theme = $service->themeFor('corporate');
        $css = $service->themeCss($theme);
        $this->assertStringContainsString('#0f172a', $css);
        $this->assertStringContainsString('background:#ffffff', $css);
    }

    public function test_lpo_pdf_service_is_constructable_with_theme_service(): void
    {
        $this->assertInstanceOf(
            LpoDocumentPdfService::class,
            app(LpoDocumentPdfService::class),
        );
    }
}
