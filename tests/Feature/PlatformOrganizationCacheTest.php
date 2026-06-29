<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformOrganizationCacheTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_clear_organization_cache(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $orgId = (int) User::where('username', 'admin')->value('organization_id');

        $this->getJson("/api/v1/admin/organizations/{$orgId}/cache")
            ->assertOk()
            ->assertJsonPath('organization_id', $orgId);

        $response = $this->postJson("/api/v1/admin/organizations/{$orgId}/cache/clear");

        $response->assertOk()
            ->assertJsonPath('cleared', true)
            ->assertJsonPath('organization_id', $orgId);
    }

    public function test_non_super_admin_cannot_clear_organization_cache(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $orgId = (int) User::where('username', 'admin')->value('organization_id');

        $this->postJson("/api/v1/admin/organizations/{$orgId}/cache/clear")
            ->assertForbidden();
    }
}
