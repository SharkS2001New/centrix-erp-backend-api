<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserAccountGuardTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_user_cannot_delete_their_own_account(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
    }

    public function test_user_cannot_disable_their_own_login(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_organization_administrator_cannot_be_deleted(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $otherAdmin = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'second_admin',
            'password' => Hash::make('password'),
            'full_name' => 'Second Admin',
            'access_scope' => 'org',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$otherAdmin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
    }

    public function test_organization_administrator_login_cannot_be_disabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $role = Role::where('role_name', 'Cashier')->firstOrFail();

        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'regular_staff',
            'password' => Hash::make('password'),
            'full_name' => 'Regular Staff',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);

        $this->putJson("/api/v1/users/{$staff->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_regular_user_can_be_archived_by_admin(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $role = Role::where('role_name', 'Cashier')->firstOrFail();

        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'archive_me',
            'password' => Hash::make('password'),
            'full_name' => 'Archive Me',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('message', 'User archived (soft deleted). Sales and activity history are retained.');

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
    }
}
