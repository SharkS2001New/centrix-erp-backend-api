<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformAdminUserManagementTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_reset_tenant_user_password_via_nested_admin_route(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $tenantUser = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $tenantUser->organization_id;

        $this->putJson("/api/v1/admin/organizations/{$orgId}/users/{$tenantUser->id}", [
            'password' => 'newpassword123',
            'must_change_password' => true,
        ])->assertOk();

        $tenantUser->refresh();
        $this->assertTrue(Hash::check('newpassword123', $tenantUser->password));
        $this->assertTrue($tenantUser->must_change_password);
    }
}
