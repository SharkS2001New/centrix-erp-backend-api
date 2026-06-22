<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformOrganizationDeleteTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_delete_tenant_organization(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $orgName = $org->org_name;

        $this->deleteJson("/api/v1/admin/organizations/{$org->id}", [
            'confirmation' => $orgName,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Organization deleted. All users have been signed out and can no longer sign in.');

        $this->assertSoftDeleted('organizations', ['id' => $org->id]);

        $this->getJson('/api/v1/admin/organizations')
            ->assertOk()
            ->assertJsonMissing(['company_code' => 'DEMO']);

        $this->getJson("/api/v1/admin/organizations/{$org->id}")
            ->assertNotFound();
    }

    public function test_delete_requires_matching_organization_name(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $orgId = (int) Organization::where('company_code', 'DEMO')->value('id');

        $this->deleteJson("/api/v1/admin/organizations/{$orgId}", [
            'confirmation' => 'Wrong name',
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['confirmation']);
    }

    public function test_non_super_admin_cannot_delete_organization(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $orgId = (int) User::where('username', 'admin')->value('organization_id');
        $orgName = Organization::findOrFail($orgId)->org_name;

        $this->deleteJson("/api/v1/admin/organizations/{$orgId}", [
            'confirmation' => $orgName,
            'password' => 'password',
        ])
            ->assertForbidden();
    }
}
