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

    /** Health probe via agent on checkout (keep short so POS never hangs). */
    public const HEALTH_WAIT_SECONDS = 8;

    /** Admin Test connection can wait a bit longer than checkout. */
    public const ADMIN_HEALTH_WAIT_SECONDS = 12;

    /** complete-workflow / PLU / init / restart (admin / background). */
    public const COMMAND_WAIT_SECONDS = 55;

    /**
     * Checkout: no per-receipt health. Agent heartbeats probe Comstore in the background.
     * Soft-skip only when heartbeat already says Comstore is down; otherwise one complete-workflow.
     * Budget must cover one sequential Comstore sale under light queueing.
     */
    public const CHECKOUT_MAX_SECONDS = 30;

    /** Unused on checkout (heartbeat replaces it). Kept for admin Test connection helpers. */
    public const CHECKOUT_HEALTH_WAIT_SECONDS = 3;

    /** Full budget goes to complete-workflow so the receipt can print with eTIMS QR. */
    public const CHECKOUT_COMMAND_WAIT_SECONDS = 28;

    /** Reclaim processing rows that never got a result (agent crash / kill mid-batch). */
    public const STALE_PROCESSING_SECONDS = 90;

    /** Claim at most one pending command so continuous sales do not starve later waiters. */
    public const PULL_COMMAND_LIMIT = 1;

    public const PING_PATH = '/agent/ping';

    public const PING_WAIT_SECONDS = 12;

    /** Marker from CentrixKraAgent when Comstore auto-start failed. */
    public const COMSTORE_MANUAL_START_PREFIX = 'COMSTORE_MANUAL_START_REQUIRED';

    public static function comstoreManualStartUserMessage(string $comstoreUrl = 'http://localhost:4000'): string
    {
        $url = trim($comstoreUrl) !== '' ? trim($comstoreUrl) : 'http://localhost:4000';

        return 'Centrix KRA Agent is still running. Start Comstore (Windows startup or the Comstore service/app — usually '.$url.'), then click Test connection again. The agent keeps pinging Centrix and the fiscal device until Comstore is reachable.';
    }

    public static function isComstoreManualStartRequired(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $lower = strtolower($message);

        return str_contains($message, self::COMSTORE_MANUAL_START_PREFIX)
            || str_contains($lower, 'start comstore manually')
            || str_contains($lower, 'could not start comstore');
    }

    /** @var list<string> */
    private const ALLOWED_PATH_PREFIXES = [
        '/api/health',
        '/api/complete-workflow',
        '/api/init',
        '/api/restart-device',
        '/api/upload-plu-data',
        '/api/register-plu',
        '/agent/ping',
        '/agent/device-probe',
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
            'comstore_reachable' => $agent->comstore_reachable,
            'comstore_status_message' => $agent->comstore_status_message,
            'manual_start_required' => self::isComstoreManualStartRequired(
                (string) ($agent->comstore_status_message ?? ''),
            ) || $agent->comstore_reachable === false,
            'device_reachable' => $agent->device_reachable,
            'device_status_message' => $agent->device_status_message,
            'device_hardware_ip' => $agent->device_hardware_ip,
            'device_connection' => $agent->device_connection,
            'device_network_error' => $online
                && $agent->comstore_reachable !== false
                && $agent->device_reachable === false,
            'poll_interval_seconds' => 0.05,
            'long_poll_ms' => 400,
            'online_ttl_seconds' => $this->onlineTtlSeconds(),
        ];
    }

    public function touchAgent(
        KraAgent $agent,
        ?string $version = null,
        ?bool $comstoreReachable = null,
        ?string $comstoreStatusMessage = null,
        ?bool $deviceReachable = null,
        ?string $deviceStatusMessage = null,
        ?string $deviceHardwareIp = null,
        ?string $deviceConnection = null,
    ): void {
        $agent->agent_last_seen_at = AppTimezone::now();
        if ($version !== null && $version !== '') {
            $agent->agent_version = mb_substr($version, 0, 40);
        }
        if ($comstoreReachable !== null) {
            $agent->comstore_reachable = $comstoreReachable;
        }
        if ($comstoreStatusMessage !== null) {
            $agent->comstore_status_message = mb_substr(trim($comstoreStatusMessage), 0, 500) ?: null;
        }
        if ($deviceReachable !== null) {
            $agent->device_reachable = $deviceReachable;
        }
        if ($deviceStatusMessage !== null) {
            $agent->device_status_message = mb_substr(trim($deviceStatusMessage), 0, 500) ?: null;
        }
        if ($deviceHardwareIp !== null) {
            $agent->device_hardware_ip = mb_substr(trim($deviceHardwareIp), 0, 100) ?: null;
        }
        if ($deviceConnection !== null) {
            $agent->device_connection = mb_substr(trim($deviceConnection), 0, 80) ?: null;
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
            return 'http://localhost:4000';
        }
        if (! str_starts_with($base, 'http://') && ! str_starts_with($base, 'https://')) {
            $base = 'http://'.$base;
        }

        $base = rtrim($base, '/');

        // Accept localhost and 127.0.0.1 interchangeably for local Comstore.
        $parts = parse_url($base);
        if (is_array($parts) && isset($parts['host'])) {
            $host = strtolower((string) $parts['host']);
            if ($host === '127.0.0.1' || $host === 'localhost' || $host === '::1') {
                $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';
                $path = (string) ($parts['path'] ?? '');
                // Prefer the host the user typed (localhost vs 127.0.0.1); keep ::1 as localhost.
                $normalizedHost = $host === '::1' ? 'localhost' : (string) $parts['host'];

                return $scheme.'://'.$normalizedHost.$port.$path;
            }
        }

        return $base;
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
                    ? self::AGENT_NAME.' has not checked in recently. On the PC where Comstore runs, open http://127.0.0.1:9261 and confirm the CentrixKraAgent Windows service is running.'
                    : self::AGENT_NAME.' has never checked in. Download Centrix KRA Agent from Finance settings and install it where Comstore runs.',
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
            || (strtoupper($method) === 'GET' && $path === '/agent/device-probe')
            || (strtoupper($method) === 'POST' && (
                $path === '/api/complete-workflow'
                || str_starts_with($path, '/api/register')
                || $path === '/api/init'
                || $path === '/api/restart-device'
            ))
        )) {
            $requestBody = $body;
            $resultStatus = 200;
            if (strtoupper($method) === 'PING') {
                $responseBody = json_encode(['pong' => true, 'agent' => self::AGENT_NAME]);
            } elseif ($path === '/agent/device-probe') {
                $hardware = is_array($requestBody) ? trim((string) ($requestBody['hardware_ip'] ?? '')) : '';
                $responseBody = json_encode(config('testing.kra_agent_device_probe_response') ?? [
                    'success' => true,
                    'reachable' => true,
                    'hardware_ip' => $hardware,
                    'device_connection' => 'Connected',
                    'ping_ok' => true,
                    'comstore_healthy' => true,
                    'message' => $hardware !== ''
                        ? "Fiscal device reachable at {$hardware} (ICMP OK)"
                        : 'Comstore healthy.',
                ]);
            } elseif ($path === '/api/health') {
                $responseBody = json_encode(config('testing.kra_agent_health_response') ?? [
                    'status' => 'OK',
                    'deviceConnection' => 'Connected',
                    'apiService' => 'Comstore',
                    'version' => 'test',
                ]);
            } else {
                // complete-workflow / register / init / restart — configurable for checkout tests.
                $responseBody = json_encode(config('testing.kra_agent_workflow_response') ?? [
                    'success' => true,
                    'message' => 'OK',
                    'invoice_number' => 'CU-TEST',
                    'Receipt Signature' => 'SIG-TEST',
                    'signature_link' => 'https://example.test/qr',
                    'serial_number' => 'TEST-SERIAL',
                    'timestamp' => '2026-06-11T12:00:00',
                ]);
                $resultStatus = (int) (config('testing.kra_agent_workflow_status') ?? 200);
            }
            $this->submitCommandResult($agent, $commandId, [
                'success' => $resultStatus >= 200 && $resultStatus < 300,
                'status' => $resultStatus,
                'body' => $responseBody,
                'headers' => ['Content-Type' => ['application/json']],
                'agent_version' => '1.0.0',
            ]);
        }

        $deadline = microtime(true) + $waitSeconds;

        do {
            /** @var KraAgentCommand|null $command */
            $command = KraAgentCommand::query()
                ->select([
                    'id',
                    'status',
                    'response_status',
                    'response_body',
                    'response_headers',
                    'error_message',
                ])
                ->find($commandId);

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

            // Poll faster at first so QR returns as soon as the agent posts; ease off later.
            $elapsed = microtime(true) - ($deadline - $waitSeconds);
            usleep($elapsed < 2.0 ? 10_000 : 20_000);
        } while (microtime(true) < $deadline);

        KraAgentCommand::query()
            ->where('id', $commandId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'expired']);

        throw new RuntimeException(
            self::AGENT_NAME.' did not respond in time. Confirm CentrixKraAgent is running and Comstore is up on '.$agent->comstore_base_url.'.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullPendingCommands(
        KraAgent $agent,
        int $limit = self::PULL_COMMAND_LIMIT,
        ?string $agentVersion = null,
        bool $touch = true,
        bool $reclaim = true,
    ): array {
        if ($touch) {
            $this->touchAgent($agent, $agentVersion);
        }
        if ($reclaim) {
            $this->reclaimStaleProcessingCommands($agent);
        }

        $limit = max(1, min(self::PULL_COMMAND_LIMIT, $limit));
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
     * Stuck "processing" rows block the queue after an agent restart mid-sale.
     * Expired → expired; still within expires_at → pending so the agent can retry.
     */
    public function reclaimStaleProcessingCommands(KraAgent $agent): int
    {
        $now = AppTimezone::now();
        $staleBefore = $now->copy()->subSeconds(self::STALE_PROCESSING_SECONDS)->format('Y-m-d H:i:s');
        $nowStr = $now->format('Y-m-d H:i:s');

        $expired = KraAgentCommand::query()
            ->where('kra_agent_id', $agent->id)
            ->where('status', 'processing')
            ->where('expires_at', '<=', $nowStr)
            ->update(['status' => 'expired']);

        $reclaimed = KraAgentCommand::query()
            ->where('kra_agent_id', $agent->id)
            ->where('status', 'processing')
            ->where('created_at', '<', $staleBefore)
            ->where('expires_at', '>', $nowStr)
            ->update(['status' => 'pending']);

        return (int) $expired + (int) $reclaimed;
    }

    public function hasActiveCommands(KraAgent $agent): bool
    {
        $now = AppTimezone::now()->format('Y-m-d H:i:s');

        return KraAgentCommand::query()
            ->where('kra_agent_id', $agent->id)
            ->whereIn('status', ['pending', 'processing'])
            ->where('expires_at', '>', $now)
            ->exists();
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
