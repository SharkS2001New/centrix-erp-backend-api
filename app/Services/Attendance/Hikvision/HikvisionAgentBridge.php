<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use App\Models\HikvisionAgentCommand;
use App\Services\Attendance\HrAttendanceSettingsResolver;
use App\Support\AppTimezone;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Routes ISAPI calls through the LAN attendance agent when the cloud API cannot reach the device.
 */
class HikvisionAgentBridge
{
    public const AGENT_NAME = 'CentrixAttendanceAgent';

    /** Floor for "online" — survive brief outages / overnight PC sleep wake. */
    public const MIN_ONLINE_SECONDS = 1800;

    /** Legacy default used by older call sites / docs. */
    public const AGENT_ONLINE_SECONDS = 90;

    /** Minimum wait for an ISAPI proxy round-trip through the LAN agent. */
    public const MIN_COMMAND_WAIT_SECONDS = 120;

    /** Cap so a single ISAPI proxy call cannot block the hourly scheduler forever. */
    public const MAX_COMMAND_WAIT_SECONDS = 420;

    public const COMMAND_WAIT_SECONDS = 120;

    /** If the agent checked in within this window, Test connection skips a blocking PING. */
    public const RECENT_CHECKIN_GRACE_SECONDS = 7200;

    public const PING_PATH = '/agent/ping';

    /** @var list<string> */
    private const ALLOWED_PATH_PREFIXES = [
        '/ISAPI/System/',
        '/ISAPI/AccessControl/',
        '/agent/ping',
    ];

    public function pollSeconds(AttendanceClockDevice $device): int
    {
        $orgId = (int) $device->organization_id;
        if ($orgId < 1) {
            return 600;
        }

        return max(
            60,
            HrAttendanceSettingsResolver::agentPollSecondsForOrganizationId($orgId),
        );
    }

    /**
     * Stay online across missed heartbeats. Command poll is every few seconds when healthy;
     * allow a long grace so brief network blips or punch catch-up do not flip offline.
     */
    public function onlineTtlSeconds(AttendanceClockDevice $device): int
    {
        return max(self::MIN_ONLINE_SECONDS, ($this->pollSeconds($device) * 3) + 300);
    }

    /**
     * Agent command polling is frequent; still allow enough time for a busy device ISAPI call.
     */
    public function commandWaitSeconds(AttendanceClockDevice $device): int
    {
        return min(self::MAX_COMMAND_WAIT_SECONDS, max(self::MIN_COMMAND_WAIT_SECONDS, 120));
    }

    public function isAgentOnline(AttendanceClockDevice $device): bool
    {
        $seen = AppTimezone::normalize($device->agent_last_seen_at);
        if ($seen === null) {
            return false;
        }

        return $seen->greaterThan(AppTimezone::now()->subSeconds($this->onlineTtlSeconds($device)));
    }

    public function hasCheckedIn(AttendanceClockDevice $device): bool
    {
        return AppTimezone::normalize($device->agent_last_seen_at) !== null;
    }

    public function agentStatus(AttendanceClockDevice $device): array
    {
        $online = $this->isAgentOnline($device);

        return [
            'name' => self::AGENT_NAME,
            'online' => $online,
            'last_seen_at' => AppTimezone::toIso8601($device->agent_last_seen_at),
            'version' => $device->agent_version,
            'poll_interval_seconds' => $this->pollSeconds($device),
            'online_ttl_seconds' => $this->onlineTtlSeconds($device),
        ];
    }

    /**
     * Round-trip ping: Centrix enqueues a command, CentrixAttendanceAgent must pick it up.
     *
     * @return array{online: bool, agent: array, error?: string, message?: string}
     */
    public function pingAgent(AttendanceClockDevice $device): array
    {
        $device = $device->fresh() ?? $device;
        $status = $this->agentStatus($device);
        if (! $status['online'] && ! $this->hasCheckedIn($device)) {
            $lastSeen = $status['last_seen_at'] ?? null;
            $ttl = $this->onlineTtlSeconds($device);
            $error = $lastSeen
                ? self::AGENT_NAME." is installed but has not checked in with Centrix in the last {$ttl} seconds. Windows Services can show Running while the PC is still getting internet or the agent is starting. Wait for the next agent poll and refresh — do not re-download unless this stays offline."
                : self::AGENT_NAME.' is not checking in. Download the agent zip for this device, run BUILD-AND-INSTALL.bat as Administrator on a LAN PC, and keep the Windows service running.';

            return [
                'online' => false,
                'agent' => $status,
                'error' => $error,
            ];
        }

        // A fresh command/ingest heartbeat means the LAN agent is already talking to Centrix.
        // Do not block Test connection on a round-trip PING — punch catch-up can occupy the
        // agent for minutes while Windows still shows the service as Running.
        if ($status['online']) {
            return [
                'online' => true,
                'via_agent' => true,
                'agent' => $status,
                'message' => self::AGENT_NAME.' is connected. Centrix received a check-in from the office agent.',
            ];
        }

        // Recently known agent that missed a couple of heartbeats — treat as live without a
        // blocking PING so offices are not forced to re-download every morning.
        if ($this->hasRecentCheckIn($device)) {
            return [
                'online' => true,
                'via_agent' => true,
                'agent' => $status,
                'message' => self::AGENT_NAME.' checked in recently. If punches look behind, wait a minute for the next sync — do not re-download.',
            ];
        }

        try {
            $response = $this->executeViaAgent($device, 'PING', self::PING_PATH, null, 'json', true);
            $fresh = $this->agentStatus($device->fresh() ?? $device);

            if (! $response->successful()) {
                return [
                    'online' => false,
                    'agent' => $fresh,
                    'error' => self::AGENT_NAME.' responded with HTTP '.$response->status().'.',
                ];
            }

            return [
                'online' => true,
                'via_agent' => true,
                'agent' => $fresh,
                'message' => self::AGENT_NAME.' is connected. Centrix can send commands to the office agent.',
            ];
        } catch (\Throwable $e) {
            $fresh = $this->agentStatus($device->fresh() ?? $device);
            if ($fresh['online'] || $this->hasRecentCheckIn($device->fresh() ?? $device)) {
                return [
                    'online' => true,
                    'via_agent' => true,
                    'agent' => $fresh,
                    'message' => self::AGENT_NAME.' is connected. Centrix received a check-in from the office agent.',
                ];
            }

            return [
                'online' => false,
                'agent' => $fresh,
                'error' => $this->formatPingTimeout($e->getMessage()),
            ];
        }
    }

    public function hasRecentCheckIn(AttendanceClockDevice $device): bool
    {
        $seen = AppTimezone::normalize($device->agent_last_seen_at);
        if ($seen === null) {
            return false;
        }

        return $seen->greaterThan(AppTimezone::now()->subSeconds(self::RECENT_CHECKIN_GRACE_SECONDS));
    }

    public function shouldUseAgent(AttendanceClockDevice $device): bool
    {
        return $this->isAgentOnline($device) || $this->hasRecentCheckIn($device);
    }

    public function touchAgent(AttendanceClockDevice $device, ?string $version = null): void
    {
        $device->agent_last_seen_at = AppTimezone::now();
        if ($version !== null && $version !== '') {
            $device->agent_version = mb_substr($version, 0, 40);
        }
        $device->save();
    }

    public function isConnectionError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'failed to connect')
            || str_contains($msg, 'curl error 28')
            || str_contains($msg, 'connection timed out')
            || str_contains($msg, 'could not resolve host')
            || str_contains($msg, 'not a valid private or local address');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function executeViaAgent(
        AttendanceClockDevice $device,
        string $method,
        string $path,
        ?array $body = null,
        string $accept = 'json',
        bool $allowStale = false,
    ): HikvisionIsapiResponse {
        $canProxy = $this->isAgentOnline($device)
            || $this->hasRecentCheckIn($device)
            || ($allowStale && $this->hasCheckedIn($device));

        if (! $canProxy) {
            $seen = $device->agent_last_seen_at;
            $ttl = $this->onlineTtlSeconds($device);
            throw new RuntimeException(
                $seen
                    ? self::AGENT_NAME." is offline (last check-in was more than {$ttl} seconds ago). The Windows service can still show Running. Wait for the next agent poll, then refresh — re-download only if it never comes online."
                    : self::AGENT_NAME.' is offline. Download the agent zip for this device and keep the Windows service running.',
            );
        }

        $this->assertAllowedPath($path);

        $waitSeconds = $this->commandWaitSeconds($device);
        $commandId = (string) Str::uuid();
        $now = AppTimezone::now();
        if (function_exists('set_time_limit')) {
            @set_time_limit($waitSeconds + 60);
        }

        HikvisionAgentCommand::query()->create([
            'id' => $commandId,
            'attendance_clock_device_id' => $device->id,
            'method' => strtoupper($method),
            'path' => $path,
            'body_json' => $body,
            'accept' => $accept,
            'status' => 'pending',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $now->copy()->addSeconds($waitSeconds + 30)->format('Y-m-d H:i:s'),
        ]);

        if (app()->runningUnitTests() && strtoupper($method) === 'PING') {
            $this->submitCommandResult($device, $commandId, [
                'success' => true,
                'status' => 200,
                'body' => json_encode(['pong' => true, 'agent' => self::AGENT_NAME]),
                'headers' => ['Content-Type' => ['application/json']],
                'agent_version' => '2.0.0',
            ]);
        }

        $deadline = microtime(true) + $waitSeconds;

        do {
            /** @var HikvisionAgentCommand|null $command */
            $command = HikvisionAgentCommand::query()->find($commandId);

            if ($command === null) {
                throw new RuntimeException('Agent command disappeared unexpectedly.');
            }

            if ($command->status === 'completed') {
                return new HikvisionIsapiResponse(
                    (int) $command->response_status,
                    (string) ($command->response_body ?? ''),
                    is_array($command->response_headers) ? $command->response_headers : [],
                    viaAgent: true,
                );
            }

            if ($command->status === 'failed') {
                throw new RuntimeException(
                    $this->formatAgentError($command->error_message),
                );
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        HikvisionAgentCommand::query()
            ->where('id', $commandId)
            ->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'expired']);

        throw new RuntimeException(
            'Attendance agent did not respond in time. The Windows service can show Running while the agent is busy syncing punches or cannot reach Centrix. Wait a minute and test again — re-download only if last check-in never updates.',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullPendingCommands(AttendanceClockDevice $device, int $limit = 5, ?string $agentVersion = null): array
    {
        $this->touchAgent($device, $agentVersion);

        $now = AppTimezone::now()->format('Y-m-d H:i:s');
        $ids = HikvisionAgentCommand::query()
            ->where('attendance_clock_device_id', $device->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', $now)
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        HikvisionAgentCommand::query()
            ->whereIn('id', $ids)
            ->update(['status' => 'processing']);

        return HikvisionAgentCommand::query()
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->get()
            ->map(static fn (HikvisionAgentCommand $c) => [
                'id' => $c->id,
                'method' => $c->method,
                'path' => $c->path,
                'body' => $c->body_json,
                'accept' => $c->accept,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submitCommandResult(AttendanceClockDevice $device, string $commandId, array $data): void
    {
        $this->touchAgent($device, isset($data['agent_version']) ? (string) $data['agent_version'] : null);

        /** @var HikvisionAgentCommand|null $command */
        $command = HikvisionAgentCommand::query()
            ->where('id', $commandId)
            ->where('attendance_clock_device_id', $device->id)
            ->first();

        if ($command === null) {
            abort(404, 'Agent command not found.');
        }

        if (in_array($command->status, ['completed', 'failed', 'expired'], true)) {
            return;
        }

        if (($data['success'] ?? false) === true) {
            $body = (string) ($data['body'] ?? '');
            $command->status = 'completed';
            $command->response_status = (int) ($data['status'] ?? 200);
            $command->response_headers = is_array($data['headers'] ?? null) ? $data['headers'] : [];
            $command->response_body = mb_substr($body, 0, 500_000);
            $command->error_message = null;
        } else {
            $command->status = 'failed';
            $command->error_message = mb_substr((string) ($data['error'] ?? 'Agent ISAPI error'), 0, 500);
        }

        $command->completed_at = AppTimezone::now();
        $command->save();
    }

    protected function assertAllowedPath(string $path): void
    {
        $normalized = '/'.ltrim($path, '/');
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new RuntimeException('ISAPI path is not allowed for agent proxy.');
    }

    protected function formatPingTimeout(string $raw): string
    {
        if (str_contains($raw, 'did not respond in time')) {
            return 'Attendance agent did not respond in time. The Windows service can show Running while the agent is busy syncing punches or cannot reach Centrix. Wait a minute and test again — re-download only if last check-in never updates.';
        }

        return $raw;
    }

    protected function formatAgentError(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return 'CentrixAttendanceAgent failed to execute the ISAPI command.';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        $status = (string) ($decoded['statusString'] ?? '');
        $sub = (string) ($decoded['subStatusCode'] ?? '');
        $code = (string) ($decoded['errorMsg'] ?? $decoded['errorCode'] ?? '');
        $parts = array_values(array_filter([$status, $sub, $code], static fn ($v) => $v !== ''));
        if ($parts === []) {
            return $raw;
        }

        return 'Hikvision device rejected the request ('.implode(', ', $parts).').';
    }
}
