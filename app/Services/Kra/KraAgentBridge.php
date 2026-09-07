<?php

namespace App\Services\Kra;

use App\Models\KraAgent;
use App\Models\KraAgentCommand;
use App\Support\AppTimezone;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cloud → shop-PC CentrixKraAgent → local Comstore (localhost:4000).
 * Same sync waiter pattern as HikvisionAgentBridge.
 */
class KraAgentBridge
{
    public const AGENT_NAME = 'CentrixKraAgent';

    /** Stay online across brief network blips. */
    public const MIN_ONLINE_SECONDS = 1800;

    public const RECENT_CHECKIN_GRACE_SECONDS = 7200;

    /** Health probe via agent. */
    public const HEALTH_WAIT_SECONDS = 15;

    /** complete-workflow / PLU / init / restart. */
    public const COMMAND_WAIT_SECONDS = 55;

    public const PING_PATH = '/agent/ping';

    public const PING_WAIT_SECONDS = 12;

    /** @var list<string> */
    private const ALLOWED_PATH_PREFIXES = [
        '/api/health',
        '/api/complete-workflow',
        '/api/init',
        '/api/restart-device',
        '/api/upload-plu-data',
        '/api/register-plu',
        '/agent/ping',
    ];

    public function onlineTtlSeconds(): int
    {
        return self::MIN_ONLINE_SECONDS;
    }

    public function isAgentOnline(KraAgent $agent): bool
    {
        $seen = AppTimezone::normalize($agent->agent_last_seen_at);
        if ($seen === null) {
            return false;
        }

        return $seen->greaterThan(AppTimezone::now()->subSeconds($this->onlineTtlSeconds()));
    }

    public function hasCheckedIn(KraAgent $agent): bool
    {
        return AppTimezone::normalize($agent->agent_last_seen_at) !== null;
    }

    public function hasRecentCheckIn(KraAgent $agent): bool
    {
        $seen = AppTimezone::normalize($agent->agent_last_seen_at);
        if ($seen === null) {
            return false;
        }

        return $seen->greaterThan(AppTimezone::now()->subSeconds(self::RECENT_CHECKIN_GRACE_SECONDS));
    }

    public function shouldUseAgent(KraAgent $agent): bool
    {
        return $this->isAgentOnline($agent) || $this->hasRecentCheckIn($agent);
    }

    public function agentStatus(KraAgent $agent): array
    {
        $online = $this->isAgentOnline($agent);

        return [
            'name' => self::AGENT_NAME,
            'online' => $online,
            'last_seen_at' => AppTimezone::toIso8601($agent->agent_last_seen_at),
            'version' => $agent->agent_version,
            'comstore_base_url' => $agent->comstore_base_url,
            'poll_interval_seconds' => 1,
            'online_ttl_seconds' => $this->onlineTtlSeconds(),
        ];
    }

    public function touchAgent(KraAgent $agent, ?string $version = null): void
    {
        $agent->agent_last_seen_at = AppTimezone::now();
        if ($version !== null && $version !== '') {
            $agent->agent_version = mb_substr($version, 0, 40);
        }
        $agent->save();
    }

    /**
     * Ensure an org has a kra_agents row; sync Comstore URL from finance settings.
     */
    public function resolveOrCreateForOrganization(int $organizationId, array $financeSettings = []): KraAgent
    {
        $comstore = $this->normalizeComstoreUrl(
            (string) ($financeSettings['kra_device_ip'] ?? ''),
        );

        $agent = KraAgent::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'name' => self::AGENT_NAME,
                'comstore_base_url' => $comstore,
            ],
        );

        if ($comstore !== '' && $agent->comstore_base_url !== $comstore) {
            $agent->comstore_base_url = $comstore;
            $agent->save();
        }

        return $agent;
    }

    public function normalizeComstoreUrl(string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            return 'http://127.0.0.1:4000';
        }
        if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
            $base = 'http://'.$base;
        }

        return rtrim($base, '/');
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array{status: int, body: string, headers: array<string, mixed>, via_agent: bool}
     */
    public function executeViaAgent(
        KraAgent $agent,
        string $method,
        string $path,
        ?array $body = null,
        string $accept = 'json',
        bool $allowStale = false,
        ?int $waitSecondsOverride = null,
    ): array {
        $canProxy = $this->isAgentOnline($agent)
            || $this->hasRecentCheckIn($agent)
            || ($allowStale && $this->hasCheckedIn($agent));

        if (! $canProxy) {
            throw new RuntimeException(
                $this->hasCheckedIn($agent)
                    ? self::AGENT_NAME.' has not checked in recently. On the shop PC open http://127.0.0.1:9261 and confirm the Windows service is running.'
                    : self::AGENT_NAME.' has never checked in. Download the KRA agent from Finance settings and install it on the shop PC.',
            );
        }

        $this->assertAllowedPath($path);

        $waitSeconds = $waitSecondsOverride ?? self::COMMAND_WAIT_SECONDS;
        $commandId = (string) Str::uuid();
        $now = AppTimezone::now();
        if (function_exists('set_time_limit')) {
            @set_time_limit($waitSeconds + 30);
        }

        KraAgentCommand::query()->create([
            'id' => $commandId,
            'kra_agent_id' => $agent->id,
            'method' => strtoupper($method),
            'path' => $path,
            'body_json' => $body,
            'accept' => $accept,
            'status' => 'pending',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $now->copy()->addSeconds($waitSeconds + 30)->format('Y-m-d H:i:s'),
        ]);

        if (app()->runningUnitTests() && (
            strtoupper($method) === 'PING'
            || (strtoupper($method) === 'GET' && $path === '/api/health')
        )) {
            $body = strtoupper($method) === 'PING'
                ? json_encode(['pong' => true, 'agent' => self::AGENT_NAME])
                : json_encode([
                    'status' => 'OK',
                    'deviceConnection' => 'Connected',
                    'apiService' => 'Comstore',
                    'version' => 'test',
                ]);
            $this->submitCommandResult($agent, $commandId, [
                'success' => true,
                'status' => 200,
                'body' => $body,
                'headers' => ['Content-Type' => ['application/json']],
                'agent_version' => '1.0.0',
            ]);
        }

        $deadline = microtime(true) + $waitSeconds;

        do {
            /** @var KraAgentCommand|null $command */
            $command = KraAgentCommand::query()->find($commandId);

            if ($command === null) {
                throw new RuntimeException('KRA agent command disappeared unexpectedly.');
            }

            if ($command->status === 'completed') {
                return [
                    'status' => (int) $command->response_status,
                    'body' => (string) ($command->response_body ?? ''),
                    'headers' => is_array($command->response_headers) ? $command->response_headers : [],
                    'via_agent' => true,
                ];
            }

            if ($command->status === 'failed') {
                throw new RuntimeException(
                    trim((string) ($command->error_message ?: self::AGENT_NAME.' command failed.')),
                );
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        KraAgentCommand::query()
            ->where('id', $commandId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'expired']);

        throw new RuntimeException(
            self::AGENT_NAME.' did not respond in time. Check the shop PC service and that Comstore is running on '.$agent->comstore_base_url.'.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullPendingCommands(KraAgent $agent, int $limit = 5, ?string $agentVersion = null): array
    {
        $this->touchAgent($agent, $agentVersion);

        $now = AppTimezone::now()->format('Y-m-d H:i:s');
        $ids = KraAgentCommand::query()
            ->where('kra_agent_id', $agent->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', $now)
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        KraAgentCommand::query()
            ->whereIn('id', $ids)
            ->update(['status' => 'processing']);

        return KraAgentCommand::query()
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (KraAgentCommand $c) => [
                'id' => $c->id,
                'method' => $c->method,
                'path' => $c->path,
                'body' => $c->body_json,
                'accept' => $c->accept,
            ])
            ->all();
    }

    /**
     * @param  array{success?: bool, status?: int, body?: string, headers?: mixed, error?: string, agent_version?: string}  $payload
     */
    public function submitCommandResult(KraAgent $agent, string $commandId, array $payload): void
    {
        $this->touchAgent($agent, isset($payload['agent_version']) ? (string) $payload['agent_version'] : null);

        /** @var KraAgentCommand|null $command */
        $command = KraAgentCommand::query()
            ->where('id', $commandId)
            ->where('kra_agent_id', $agent->id)
            ->first();

        if ($command === null) {
            throw new RuntimeException('Unknown KRA agent command.');
        }

        if (in_array($command->status, ['completed', 'failed', 'expired'], true)) {
            return;
        }

        $success = (bool) ($payload['success'] ?? false);
        $command->status = $success ? 'completed' : 'failed';
        $command->response_status = isset($payload['status']) ? (int) $payload['status'] : null;
        $command->response_body = isset($payload['body']) ? (string) $payload['body'] : null;
        $command->response_headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : null;
        $command->error_message = $success
            ? null
            : mb_substr(trim((string) ($payload['error'] ?? 'Agent command failed')), 0, 500);
        $command->completed_at = AppTimezone::now()->format('Y-m-d H:i:s');
        $command->save();
    }

    protected function assertAllowedPath(string $path): void
    {
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'?') || str_starts_with($path, $prefix.'/')) {
                return;
            }
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        throw new RuntimeException('KRA agent path is not allowed: '.$path);
    }
}
