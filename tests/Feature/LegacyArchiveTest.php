<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LegacyArchiveTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_legacy_archive_status_when_disabled(): void
    {
        config(['legacy_archive.enabled' => false]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/legacy-archive/status')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('available', false);
    }

    public function test_capabilities_expose_legacy_archive_flags(): void
    {
        config(['legacy_archive.enabled' => true]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('legacy_archive_enabled', true);
    }
}
