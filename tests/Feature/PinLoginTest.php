<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Auth\PinLoginService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PinLoginTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_unlock_pin_succeeds_for_current_user(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $user->organization_id);
        app(PinLoginService::class)->assignPin($user, '2580');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/unlock-pin', ['pin' => '2580'])
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_unlock_pin_rejects_wrong_pin(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $user->organization_id);
        app(PinLoginService::class)->assignPin($user, '2580');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/unlock-pin', ['pin' => '0000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_pin_operators_lists_users_with_pins_and_hides_hash(): void
    {
        $cashier = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $cashier->organization_id);
        app(PinLoginService::class)->assignPin($cashier, '1111');

        $other = $this->makeUser(['full_name' => 'Waiter Ann']);
        app(PinLoginService::class)->assignPin($other, '2222');

        Sanctum::actingAs($cashier);

        $res = $this->getJson('/api/v1/auth/pin-operators')
            ->assertOk()
            ->json();

        $ids = collect($res['data'] ?? [])->pluck('id')->all();
        $this->assertContains((int) $cashier->id, $ids);
        $this->assertContains((int) $other->id, $ids);
        foreach ($res['data'] as $row) {
            $this->assertArrayNotHasKey('login_pin', $row);
            $this->assertTrue($row['has_login_pin']);
        }
    }

    public function test_switch_operator_issues_session_for_selected_user(): void
    {
        $cashier = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $cashier->organization_id);
        app(PinLoginService::class)->assignPin($cashier, '1111');

        $other = $this->makeUser(['full_name' => 'Waiter Ann']);
        app(PinLoginService::class)->assignPin($other, '2222');

        $this->ensureActiveSubscription((int) $cashier->organization_id);

        $token = $cashier->createToken('PIN_SWITCH_TEST', ['*']);
        $token->accessToken->forceFill([
            'organization_id' => $cashier->organization_id,
            'login_channel' => 'backoffice',
        ])->save();

        $res = $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/switch-operator', [
                'user_id' => $other->id,
                'pin' => '2222',
                'client_id' => 'PIN_SWITCH_TEST',
            ])
            ->assertOk()
            ->json();

        $this->assertSame((int) $other->id, (int) ($res['user']['id'] ?? 0));
        $this->assertNotEmpty($res['token'] ?? $res['user']['id'] ?? null);
        $this->assertArrayNotHasKey('login_pin', $res['user'] ?? []);
        $this->assertTrue((bool) ($res['user']['has_login_pin'] ?? false));
    }

    public function test_user_can_set_own_pin_with_password(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $user->organization_id);
        $user->forceFill(['login_pin' => null])->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/me/pin', [
                'pin' => '4477',
                'pin_confirmation' => '4477',
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('has_login_pin', true);

        $this->assertTrue(app(PinLoginService::class)->userHasPin($user->fresh()));
    }

    public function test_public_pin_login_issues_session_for_hospitality_user(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $user->organization_id);
        $this->ensureActiveSubscription((int) $user->organization_id);
        app(PinLoginService::class)->assignPin($user, '2580');

        $res = $this->postJson('/api/v1/auth/pin-login', [
            'company_code' => 'DEMO',
            'username' => 'admin',
            'pin' => '2580',
            'client_id' => 'HOTEL_PIN_WEB',
        ])
            ->assertOk()
            ->json();

        $this->assertSame((int) $user->id, (int) ($res['user']['id'] ?? 0));
        $this->assertTrue((bool) ($res['user']['has_login_pin'] ?? false));
        $this->assertArrayNotHasKey('login_pin', $res['user'] ?? []);
    }

    public function test_public_pin_login_rejects_wrong_pin(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $user->organization_id);
        app(PinLoginService::class)->assignPin($user, '2580');

        $this->postJson('/api/v1/auth/pin-login', [
            'company_code' => 'DEMO',
            'username' => 'admin',
            'pin' => '0000',
            'client_id' => 'HOTEL_PIN_WEB',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_public_pin_login_rejects_retail_organizations(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        app(PinLoginService::class)->assignPin($user, '2580');

        $this->postJson('/api/v1/auth/pin-login', [
            'company_code' => 'DEMO',
            'username' => 'admin',
            'pin' => '2580',
            'client_id' => 'HOTEL_PIN_WEB',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_public_pin_login_rejects_user_without_pin(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->makeOrganizationHospitality((int) $user->organization_id);
        $user->forceFill(['login_pin' => null])->save();

        $this->postJson('/api/v1/auth/pin-login', [
            'company_code' => 'DEMO',
            'username' => 'admin',
            'pin' => '2580',
            'client_id' => 'HOTEL_PIN_WEB',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    public function test_pin_operators_empty_for_retail_organizations(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        app(PinLoginService::class)->assignPin($user, '2580');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/pin-operators')
            ->assertOk()
            ->assertJsonPath('enable_pin_unlock', false)
            ->assertJsonPath('data', []);
    }

    public function test_user_cannot_set_own_pin_on_retail_organization(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/me/pin', [
                'pin' => '4477',
                'pin_confirmation' => '4477',
                'current_password' => 'password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pin']);
    }

    protected function makeOrganizationHospitality(int $organizationId): void
    {
        Organization::query()->whereKey($organizationId)->update([
            'deployment_profile' => 'hotel_bar',
        ]);
    }

    protected function ensureActiveSubscription(int $organizationId): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    protected function makeUser(array $overrides = []): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        return User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'pin_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'PIN Test User',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['backoffice', 'pos'],
            'is_active' => true,
        ], $overrides));
    }
}
