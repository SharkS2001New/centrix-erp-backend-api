<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_user_can_update_own_profile(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/me', [
            'full_name' => 'Updated Admin Name',
            'email' => 'updated-admin@example.com',
            'username' => 'admin',
        ])
            ->assertOk()
            ->assertJsonPath('full_name', 'Updated Admin Name')
            ->assertJsonPath('email', 'updated-admin@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'full_name' => 'Updated Admin Name',
            'email' => 'updated-admin@example.com',
        ]);
    }

    public function test_user_cannot_take_another_username_in_same_organization(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $other = User::query()
            ->where('organization_id', $user->organization_id)
            ->where('id', '!=', $user->id)
            ->first();

        if ($other === null) {
            $this->markTestSkipped('Need a second user in the same organization.');
        }

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/me', [
            'username' => $other->username,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }
}
