<?php

namespace Tests\Unit;

use App\Models\KraAgent;
use App\Services\Kra\KraAgentBridge;
use Illuminate\Support\Facades\Date;
use Mockery;
use Tests\TestCase;

class AgentHeartbeatWriteThrottleTest extends TestCase
{
    public function test_kra_heartbeat_within_write_interval_skips_timestamp_update(): void
    {
        Date::setTestNow(now());
        $lastSeen = now()->subSeconds(KraAgentBridge::HEARTBEAT_WRITE_INTERVAL_SECONDS - 1);
        $agent = Mockery::mock(KraAgent::class)->makePartial();
        $agent->forceFill([
            'id' => 1,
            'agent_last_seen_at' => $lastSeen,
            'agent_version' => '1.0.0',
            'comstore_reachable' => true,
        ]);
        $agent->exists = true;
        $agent->syncOriginal();
        $agent->shouldNotReceive('save');

        app(KraAgentBridge::class)->touchAgent($agent);

        $this->assertEquals($lastSeen->timestamp, $agent->agent_last_seen_at->timestamp);
    }

    public function test_kra_heartbeat_updates_timestamp_after_write_interval(): void
    {
        Date::setTestNow(now());
        $lastSeen = now()->subSeconds(KraAgentBridge::HEARTBEAT_WRITE_INTERVAL_SECONDS + 1);
        $agent = Mockery::mock(KraAgent::class)->makePartial();
        $agent->forceFill([
            'id' => 1,
            'agent_last_seen_at' => $lastSeen,
            'agent_version' => '1.0.0',
            'comstore_reachable' => true,
        ]);
        $agent->exists = true;
        $agent->syncOriginal();
        $agent->shouldReceive('save')->once()->andReturnTrue();

        app(KraAgentBridge::class)->touchAgent($agent);

        $this->assertGreaterThan($lastSeen->timestamp, $agent->agent_last_seen_at->timestamp);
    }
}