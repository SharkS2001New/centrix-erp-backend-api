<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformActiveSessionsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_list_active_sessions_grouped_by_organization(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();

        Sanctum::actingAs($cashier, ['*'], 'web');
        $cashier->createToken('DEVICE-ABC', ['*'], now()->addDay());
        $token = $cashier->tokens()->first();
        $token->forceFill([
            'organization_id' => $cashier->organization_id,
            'login_channel' => 'pos',
        ])->save();

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/admin/active-sessions');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'organization' => ['id', 'company_code', 'org_name'],
                        'sessions' => [
                            '*' => [
                                'id', 'user_id', 'username', 'login_channel', 'computer_id',
                                'last_active_at', 'session_started_at',
                            ],
                        ],
                    ],
                ],
            ]);

        $sessions = collect($response->json('data'))->flatMap(fn ($g) => $g['sessions']);
        $this->assertTrue($sessions->contains('username', 'cashier'));
        $this->assertTrue($sessions->contains('computer_id', 'DEVICE-ABC'));
    }

    public function test_super_admin_can_end_active_session(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();

        Sanctum::actingAs($cashier, ['*'], 'web');
        $cashier->createToken('DEVICE-END', ['*'], now()->addDay());
        $tokenId = (int) $cashier->tokens()->first()->id;
        $cashier->tokens()->first()->forceFill([
            'organization_id' => $cashier->organization_id,
            'login_channel' => 'backoffice',
        ])->save();

        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/v1/admin/active-sessions/{$tokenId}")
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}
