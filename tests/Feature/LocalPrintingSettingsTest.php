<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LocalPrintingSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);
    }

    public function test_local_printing_defaults_to_browser(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/erp/settings/local-printing')
            ->assertOk()
            ->assertJsonPath('local_printing.provider', 'browser')
            ->assertJsonPath('local_printing.fallback_to_browser', true);
    }

    public function test_can_update_local_printing_to_qz(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/erp/settings/local-printing', [
            'provider' => 'qz',
            'printer_name' => 'EPSON TM-T20II',
            'fallback_to_browser' => true,
            'require_qz' => false,
            'use_signing' => false,
        ])
            ->assertOk()
            ->assertJsonPath('local_printing.provider', 'qz')
            ->assertJsonPath('local_printing.printer_name', 'EPSON TM-T20II');

        $this->getJson('/api/v1/erp/settings/local-printing')
            ->assertOk()
            ->assertJsonPath('local_printing.provider', 'qz')
            ->assertJsonPath('local_printing.printer_name', 'EPSON TM-T20II');
    }

    public function test_can_update_local_printing_to_print_agent(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/erp/settings/local-printing', [
            'provider' => 'print-agent',
            'printer_name' => 'Star TSP143',
            'fallback_to_browser' => true,
        ])
            ->assertOk()
            ->assertJsonPath('local_printing.provider', 'agent')
            ->assertJsonPath('local_printing.printer_name', 'Star TSP143');

        $this->getJson('/api/v1/erp/settings/local-printing')
            ->assertOk()
            ->assertJsonPath('local_printing.provider', 'agent');
    }
}
