<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\Background\ReportBrandingService;
use App\Services\Background\ReportExportService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportBrandingTest extends TestCase
{
    public function test_resolve_display_prefers_logo_when_auto_and_logo_exists(): void
    {
        $service = new ReportBrandingService;

        $this->assertSame('logo', $service->resolveDisplay('auto', true));
        $this->assertSame('name', $service->resolveDisplay('auto', false));
        $this->assertSame('name', $service->resolveDisplay('name', true));
        $this->assertSame('logo_and_name', $service->resolveDisplay('logo_and_name', true));
    }

    public function test_build_print_html_includes_centered_header_and_watermark(): void
    {
        Storage::fake('public');
        $logoPath = 'organizations/1/logo.png';
        Storage::disk('public')->put($logoPath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $organization = new Organization([
            'org_name' => 'Moonlight Traders',
            'logo' => $logoPath,
            'module_settings' => [
                'general' => [
                    'show_organization_on_documents' => true,
                    'document_header_display' => 'logo_and_name',
                ],
            ],
        ]);
        $organization->id = 1;

        $brandingService = new ReportBrandingService;
        $branding = $brandingService->forOrganization($organization);
        $meta = [
            'organization_name' => 'Moonlight Traders',
            'title' => 'Sales Summary',
            'printed_at' => '2026-06-20 12:00',
            'branding' => $branding,
        ];

        $exporter = new ReportExportService($brandingService);
        $method = new \ReflectionMethod(ReportExportService::class, 'buildPrintHtml');
        $method->setAccessible(true);
        $html = $method->invoke($exporter, $meta, [
            ['key' => 'amount', 'label' => 'Amount', 'align' => 'right'],
        ], [
            ['amount' => '1,000.00'],
        ], null, null);

        $this->assertStringContainsString('class="org-header"', $html);
        $this->assertStringContainsString('class="org-logo"', $html);
        $this->assertStringContainsString('Moonlight Traders', $html);
        $this->assertStringContainsString('class="watermark"', $html);
        $this->assertStringContainsString('Sales Summary', $html);
    }
}
