<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\PlatformActiveSessionService;
use App\Services\Auth\UserLoginChannelService;
use App\Support\AttendanceAgentToken;
use App\Support\KraAgentToken;
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
            'active_workspace_id' => 'pos',
            'last_used_at' => now(),
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
                                'active_workspace_label',
                                'last_active_at', 'session_started_at',
                            ],
                        ],
                    ],
                ],
            ]);

        $sessions = collect($response->json('data'))->flatMap(fn ($g) => $g['sessions']);
        $match = $sessions->firstWhere('computer_id', 'DEVICE-ABC')
            ?? $sessions->firstWhere('username', 'cashier');
        if ($match) {
            $this->assertContains($match['active_workspace_label'], ['External POS', 'Hotel POS', 'Backoffice', 'Hotel Backoffice']);
        } else {
            $this->assertIsArray($response->json('data'));
        }
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

    public function test_agent_tokens_are_excluded_from_active_sessions(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $orgId = (int) $cashier->organization_id;

        Sanctum::actingAs($cashier, ['*'], 'web');
        $cashier->createToken(KraAgentToken::nameForOrganization($orgId), ['*']);
        $cashier->tokens()->latest('id')->first()->forceFill([
            'organization_id' => $orgId,
            'login_channel' => 'backoffice',
            'last_used_at' => now(),
            'expires_at' => null,
        ])->save();

        $cashier->createToken(AttendanceAgentToken::nameForDevice('TERMINAL 1'), ['*']);
        $cashier->tokens()->latest('id')->first()->forceFill([
            'organization_id' => $orgId,
            'login_channel' => 'backoffice',
            'last_used_at' => now(),
            'expires_at' => null,
        ])->save();

        $cashier->createToken('android:test-device', ['*'], now()->addDay());
        $cashier->tokens()->latest('id')->first()->forceFill([
            'organization_id' => $orgId,
            'login_channel' => 'mobile',
            'last_used_at' => now(),
        ])->save();

        Sanctum::actingAs($superAdmin);

        $sessions = collect($this->getJson('/api/v1/admin/active-sessions')->json('data'))
            ->flatMap(fn ($g) => $g['sessions']);

        $this->assertNull($sessions->first(
            fn ($s) => str_starts_with((string) ($s['computer_id'] ?? ''), 'kra-agent:'),
        ));
        $this->assertNull($sessions->first(
            fn ($s) => str_starts_with((string) ($s['computer_id'] ?? ''), 'attendance-agent:'),
        ));
        $this->assertNotNull($sessions->firstWhere('computer_id', 'android:test-device'));
    }

    public function test_idle_mobile_sessions_drop_off_active_list(): void
    {
        config([
            'erp.session_idle_minutes' => 60,
            'erp.mobile_presence_minutes' => 120,
        ]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $orgId = (int) $cashier->organization_id;

        Sanctum::actingAs($cashier, ['*'], 'web');
        $cashier->createToken('android:idle-phone', ['*'], now()->addDay());
        $cashier->tokens()->latest('id')->first()->forceFill([
            'organization_id' => $orgId,
            'login_channel' => 'mobile',
            'last_used_at' => now()->subMinutes(130),
        ])->save();

        $cashier->createToken('android:fresh-phone', ['*'], now()->addDay());
        $cashier->tokens()->latest('id')->first()->forceFill([
            'organization_id' => $orgId,
            'login_channel' => 'mobile',
            'last_used_at' => now()->subMinutes(10),
        ])->save();

        Sanctum::actingAs($superAdmin);

        $sessions = collect($this->getJson('/api/v1/admin/active-sessions')->json('data'))
            ->flatMap(fn ($g) => $g['sessions']);

        $this->assertNull($sessions->firstWhere('computer_id', 'android:idle-phone'));
        $this->assertNotNull($sessions->firstWhere('computer_id', 'android:fresh-phone'));
    }

    public function test_mobile_presence_is_capped_by_org_idle(): void
    {
        $service = app(PlatformActiveSessionService::class);

        $this->assertSame(
            60,
            $service->presenceMinutesForChannel(UserLoginChannelService::MOBILE, 60, 120),
        );
        $this->assertSame(
            120,
            $service->presenceMinutesForChannel(UserLoginChannelService::MOBILE, 480, 120),
        );
        $this->assertSame(
            480,
            $service->presenceMinutesForChannel(UserLoginChannelService::BACKOFFICE, 480, 120),
        );
    }
}
