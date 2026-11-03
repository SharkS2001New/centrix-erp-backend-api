<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\NightSessionPolicy;
use App\Support\AttendanceAgentToken;
use Illuminate\Support\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuthNightSessionTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_night_policy_covers_overnight_window_in_application_timezone(): void
    {
        config([
            'app.timezone' => 'Africa/Nairobi',
            'security.night_session.enabled' => true,
            'security.night_session.start' => '23:00',
            'security.night_session.end' => '06:00',
        ]);
        $policy = app(NightSessionPolicy::class);

        $this->assertTrue($policy->isActive(Carbon::parse('2026-11-03 23:00', 'Africa/Nairobi')));
        $this->assertTrue($policy->isActive(Carbon::parse('2026-11-04 05:59', 'Africa/Nairobi')));
        $this->assertFalse($policy->isActive(Carbon::parse('2026-11-04 06:00', 'Africa/Nairobi')));
        $this->assertFalse($policy->isActive(Carbon::parse('2026-11-03 22:59', 'Africa/Nairobi')));
    }

    public function test_scheduled_sweep_revokes_idle_night_sessions_but_preserves_active_and_agent_tokens(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 23:31', 'Africa/Nairobi'));
        config([
            'app.timezone' => 'Africa/Nairobi',
            'security.night_session.enabled' => true,
            'security.night_session.start' => '23:00',
            'security.night_session.end' => '06:00',
            'security.night_session.idle_minutes' => 30,
        ]);

        $user = User::where('username', 'admin')->firstOrFail();
        $stale = $user->createToken('web:idle-night');
        $user->createToken('web:active');
        $agent = $user->createToken(AttendanceAgentToken::nameForDevice('NIGHT-TEST'));
        $user->tokens()->whereKey($stale->accessToken->id)->update([
            'last_used_at' => now()->subMinutes(31),
        ]);
        $user->tokens()->where('name', 'web:active')->update([
            'last_used_at' => now()->subMinutes(29),
        ]);
        $user->tokens()->whereKey($agent->accessToken->id)->update([
            'last_used_at' => now()->subHours(8),
        ]);

        $this->artisan('erp:revoke-idle-night-sessions')->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $stale->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'web:active']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $agent->accessToken->id]);
    }

    public function test_scheduled_sweep_does_not_revoke_tokens_outside_night_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 22:59', 'Africa/Nairobi'));
        config([
            'app.timezone' => 'Africa/Nairobi',
            'security.night_session.enabled' => true,
        ]);

        $user = User::where('username', 'admin')->firstOrFail();
        $token = $user->createToken('web:before-night');
        $user->tokens()->whereKey($token->accessToken->id)->update([
            'last_used_at' => now()->subHours(2),
        ]);

        $this->artisan('erp:revoke-idle-night-sessions')->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_api_request_revokes_idle_session_overnight_even_when_global_idle_revoke_is_off(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-03 23:31', 'Africa/Nairobi'));
        config([
            'app.timezone' => 'Africa/Nairobi',
            'security.revoke_idle_tokens' => false,
            'security.night_session.enabled' => true,
            'security.night_session.start' => '23:00',
            'security.night_session.end' => '06:00',
            'security.night_session.idle_minutes' => 30,
        ]);

        $user = User::where('username', 'admin')->firstOrFail();
        $token = $user->createToken('web:idle-night-request');
        $user->tokens()->whereKey($token->accessToken->id)->update([
            'last_used_at' => now()->subMinutes(31),
        ]);
        $this->assertTrue(app(NightSessionPolicy::class)->isActive());
        $this->assertTrue($token->accessToken->fresh()->last_used_at->lt(now()->subMinutes(30)));

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'session_idle_timeout');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
