<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

/**
 * Hikvision ISAPI client (DS-K1T series access / T&A terminals).
 * Uses HTTP Digest authentication. All calls are server-side only.
 */
class HikvisionIsapiClient
{
    public function __construct(protected AttendanceClockDevice $device)
    {
    }

    // ------------------------------------------------------------------
    // Connection / device info
    // ------------------------------------------------------------------

    public function ping(): bool
    {
        return $this->http()->get($this->url('/ISAPI/System/deviceInfo'))->successful();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDeviceInfo(): array
    {
        $response = $this->http()->get($this->url('/ISAPI/System/deviceInfo'));
        $this->assertSuccessful($response, 'deviceInfo');

        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'json')) {
            return $response->json() ?? [];
        }

        return $this->parseDeviceInfoXml($response->body());
    }

    // ------------------------------------------------------------------
    // Capabilities
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getUserInfoCapabilities(): array
    {
        return $this->getJson('/ISAPI/AccessControl/UserInfo/capabilities?format=json');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAcsEventCapabilities(): array
    {
        return $this->getJson('/ISAPI/AccessControl/GetAcsEvent/capabilities?format=json');
    }

    /**
     * @return array<string, mixed>
     */
    public function getAcsEventTotalNumCapabilities(): array
    {
        return $this->getJson('/ISAPI/AccessControl/AcsEventTotalNum/capabilities?format=json');
    }

    /**
     * @return array<string, mixed>
     */
    public function discoverCapabilities(): array
    {
        $caps = [
            'users' => $this->tryCapability(fn () => $this->getUserInfoCapabilities()),
            'events' => $this->tryCapability(fn () => $this->getAcsEventCapabilities()),
            'event_count' => $this->tryCapability(fn () => $this->getAcsEventTotalNumCapabilities()),
            'cards' => $this->tryCapability(fn () => $this->getJson('/ISAPI/AccessControl/CardInfo/capabilities?format=json')),
            'fingerprints' => $this->tryCapability(fn () => $this->getJson('/ISAPI/AccessControl/FingerPrint/capabilities?format=json')),
        ];

        $caps['features'] = [
            'users' => $caps['users']['supported'] ?? false,
            'cards' => $caps['cards']['supported'] ?? false,
            'fingerprints' => $caps['fingerprints']['supported'] ?? false,
            'events' => $caps['events']['supported'] ?? false,
            'remote_fingerprint_enrollment' => $this->detectRemoteFingerprintEnrollment($caps['fingerprints']),
        ];

        return $caps;
    }

    // ------------------------------------------------------------------
    // Users / persons
    // ------------------------------------------------------------------

    public function getUserCount(): int
    {
        $payload = $this->getJson('/ISAPI/AccessControl/UserInfo/Count?format=json');

        return (int) (
            $payload['UserInfoCount']['userNumber']
            ?? $payload['UserInfoCount']['UserNumber']
            ?? 0
        );
    }

    /**
     * @param  array<string, mixed>  $cond
     * @return array{users: list<array>, total: int, has_more: bool}
     */
    public function searchUsers(array $cond = []): array
    {
        $searchId = (string) ($cond['searchID'] ?? Str::uuid());
        $position = (int) ($cond['searchResultPosition'] ?? 0);
        $maxResults = (int) ($cond['maxResults'] ?? 30);

        $body = [
            'UserInfoSearchCond' => array_filter([
                'searchID' => $searchId,
                'searchResultPosition' => $position,
                'maxResults' => $maxResults,
                'EmployeeNoList' => $cond['EmployeeNoList'] ?? null,
                'fuzzySearch' => $cond['fuzzySearch'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];

        $payload = $this->postJson('/ISAPI/AccessControl/UserInfo/Search?format=json', $body);
        $search = $payload['UserInfoSearch'] ?? $payload['UserInfoSearchCond'] ?? $payload;
        $list = $search['UserInfo'] ?? $search['InfoList'] ?? [];
        if (isset($list['employeeNo']) || isset($list['EmployeeNo'])) {
            $list = [$list];
        }
        if (! is_array($list)) {
            $list = [];
        }

        $total = (int) ($search['totalMatches'] ?? $search['numOfMatches'] ?? count($list));
        $responseStatus = strtolower((string) ($search['responseStatusStrg'] ?? ''));

        return [
            'users' => array_values(array_filter($list, 'is_array')),
            'total' => $total,
            'has_more' => $responseStatus === 'more' || count($list) >= $maxResults,
        ];
    }

    /**
     * @param  array<string, mixed>  $userInfo
     */
    public function createUser(array $userInfo): array
    {
        return $this->postJson('/ISAPI/AccessControl/UserInfo/Record?format=json', [
            'UserInfo' => $userInfo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $userInfo
     */
    public function setupUser(array $userInfo): array
    {
        return $this->putJson('/ISAPI/AccessControl/UserInfo/SetUp?format=json', [
            'UserInfo' => $userInfo,
        ]);
    }

    /**
     * @param  list<string>  $employeeNos
     */
    public function deleteUsers(array $employeeNos, string $mode = 'byEmployeeNo'): array
    {
        $list = array_map(static fn ($no) => ['employeeNo' => (string) $no], $employeeNos);

        return $this->putJson('/ISAPI/AccessControl/UserInfoDetail/Delete?format=json', [
            'UserInfoDetail' => [
                'mode' => $mode,
                'EmployeeNoList' => $list,
            ],
        ]);
    }

    public function getUserDeleteProcess(): array
    {
        return $this->getJson('/ISAPI/AccessControl/UserInfoDetail/DeleteProcess?format=json');
    }

    // ------------------------------------------------------------------
    // Cards
    // ------------------------------------------------------------------

    public function getCardCount(): int
    {
        $payload = $this->getJson('/ISAPI/AccessControl/CardInfo/Count?format=json');

        return (int) (
            $payload['CardInfoCount']['cardNumber']
            ?? $payload['CardInfoCount']['CardNumber']
            ?? 0
        );
    }

    /**
     * @param  array<string, mixed>  $cond
     */
    public function searchCards(array $cond = []): array
    {
        $searchId = (string) ($cond['searchID'] ?? Str::uuid());
        $body = [
            'CardInfoSearchCond' => array_filter([
                'searchID' => $searchId,
                'searchResultPosition' => (int) ($cond['searchResultPosition'] ?? 0),
                'maxResults' => (int) ($cond['maxResults'] ?? 30),
                'EmployeeNoList' => $cond['EmployeeNoList'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];
        $payload = $this->postJson('/ISAPI/AccessControl/CardInfo/Search?format=json', $body);
        $search = $payload['CardInfoSearch'] ?? $payload;
        $list = $search['CardInfo'] ?? $search['InfoList'] ?? [];
        if (isset($list['employeeNo']) || isset($list['cardNo'])) {
            $list = [$list];
        }

        return [
            'cards' => is_array($list) ? array_values(array_filter($list, 'is_array')) : [],
            'total' => (int) ($search['totalMatches'] ?? count((array) $list)),
        ];
    }

    /**
     * @param  array<string, mixed>  $cardInfo
     */
    public function createCard(array $cardInfo): array
    {
        return $this->postJson('/ISAPI/AccessControl/CardInfo/Record?format=json', [
            'CardInfo' => $cardInfo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $cardInfo
     */
    public function setupCard(array $cardInfo): array
    {
        return $this->putJson('/ISAPI/AccessControl/CardInfo/SetUp?format=json', [
            'CardInfo' => $cardInfo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $cardInfo
     */
    public function deleteCard(array $cardInfo): array
    {
        $cardInfo['deleteCard'] = true;

        return $this->setupCard($cardInfo);
    }

    // ------------------------------------------------------------------
    // Fingerprints
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $cond
     * @return array{fingerprints: list<array>, total: int}
     */
    public function searchFingerprints(array $cond = []): array
    {
        $searchId = (string) ($cond['searchID'] ?? Str::uuid());
        $body = [
            'FingerPrintCond' => array_filter([
                'searchID' => $searchId,
                'searchResultPosition' => (int) ($cond['searchResultPosition'] ?? 0),
                'maxResults' => (int) ($cond['maxResults'] ?? 30),
                'EmployeeNoList' => $cond['EmployeeNoList'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
        ];

        try {
            $payload = $this->postJson('/ISAPI/AccessControl/FingerPrintUpload?format=json', $body);
            $search = $payload['FingerPrintSearch'] ?? $payload['FingerPrintInfo'] ?? $payload;
            $list = $search['FingerPrintInfo'] ?? $search['InfoList'] ?? [];
            if (isset($list['employeeNo']) || isset($list['fingerPrintID'])) {
                $list = [$list];
            }

            return [
                'fingerprints' => is_array($list) ? array_values(array_filter($list, 'is_array')) : [],
                'total' => (int) ($search['totalMatches'] ?? count((array) $list)),
            ];
        } catch (\Throwable) {
            return ['fingerprints' => [], 'total' => 0];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deleteFingerprint(array $payload): array
    {
        $payload['deleteFingerPrint'] = true;

        return $this->setupFingerprint(['FingerPrintCfg' => $payload]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function uploadFingerprint(array $payload): array
    {
        return $this->postJson('/ISAPI/AccessControl/FingerPrintUpload?format=json', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function setupFingerprint(array $payload): array
    {
        return $this->postJson('/ISAPI/AccessControl/FingerPrint/SetUp?format=json', $payload);
    }

    // ------------------------------------------------------------------
    // Events / attendance
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $cond
     */
    public function countEvents(array $cond): int
    {
        $body = ['AcsEventTotalNumCond' => $cond];
        $payload = $this->postJson('/ISAPI/AccessControl/AcsEventTotalNum?format=json', $body);

        return (int) (
            $payload['AcsEventTotalNum']['totalNum']
            ?? $payload['AcsEventTotalNum']['TotalNum']
            ?? 0
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAccessEvents(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        int $maxResults = 500,
        ?array $capabilities = null,
    ): array {
        $maxPage = $this->resolveMaxResultsPerPage($capabilities);
        $searchId = (string) Str::uuid();
        $position = 0;
        $events = [];

        $baseCond = [
            'searchID' => $searchId,
            'startTime' => $from->format('Y-m-d\TH:i:sP'),
            'endTime' => $to->format('Y-m-d\TH:i:sP'),
        ];

        if ($capabilities !== null) {
            // Do not hard-code minor codes — use capability hints when present.
            $baseCond = array_merge($baseCond, $this->eventSearchDefaultsFromCapabilities($capabilities));
        }

        do {
            $body = [
                'AcsEventCond' => array_merge($baseCond, [
                    'searchResultPosition' => $position,
                    'maxResults' => min($maxPage, $maxResults - count($events)),
                ]),
            ];

            $payload = $this->postJson('/ISAPI/AccessControl/AcsEvent?format=json', $body);
            $acs = $payload['AcsEvent'] ?? $payload;
            $list = $acs['InfoList'] ?? $acs['infoList'] ?? [];
            if (! is_array($list)) {
                $list = [];
            }
            if (isset($list['employeeNo']) || isset($list['employeeNoString'])) {
                $list = [$list];
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $normalized = $this->normalizeEventRow($row);
                if ($normalized !== null) {
                    $events[] = $normalized;
                }
            }

            $matches = (int) ($acs['numOfMatches'] ?? count($list));
            $position += max(1, $matches);
            $status = strtolower((string) ($acs['responseStatusStrg'] ?? ''));

            if ($matches < 1 || count($events) >= $maxResults || $status !== 'more') {
                break;
            }
        } while (count($events) < $maxResults);

        return $events;
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function getJson(string $path): array
    {
        $response = $this->http()->get($this->url($path));
        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function postJson(string $path, array $body): array
    {
        $response = $this->http()->asJson()->post($this->url($path), $body);
        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function putJson(string $path, array $body): array
    {
        $response = $this->http()->asJson()->put($this->url($path), $body);
        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    protected function http(): PendingRequest
    {
        $username = (string) ($this->device->username ?: 'admin');
        $password = (string) ($this->device->plainPassword() ?? '');

        return Http::timeout(25)
            ->connectTimeout(8)
            ->retry(2, 500, throw: false)
            ->withDigestAuth($username, $password)
            ->withOptions(['verify' => false])
            ->acceptJson();
    }

    protected function url(string $path): string
    {
        $host = trim((string) $this->device->host);
        if ($host === '') {
            throw new RuntimeException('Clock device host/IP is not configured.');
        }
        if (! $this->isAllowedHost($host)) {
            throw new RuntimeException('Device host is not a valid private or local address.');
        }
        $scheme = $this->device->use_https ? 'https' : 'http';
        $port = self::resolvePort($this->device);
        $path = '/'.ltrim($path, '/');

        return "{$scheme}://{$host}:{$port}{$path}";
    }

    /**
     * Hikvision ISAPI defaults: HTTP 80, HTTPS 443.
     * Port 8000 is a common mistake (Centrix/Laravel dev server) — treat as 80 for HTTP.
     */
    public static function resolvePort(AttendanceClockDevice $device): int
    {
        $useHttps = (bool) $device->use_https;
        $stored = $device->port;
        if ($stored === null || $stored === '') {
            return $useHttps ? 443 : 80;
        }

        $port = (int) $stored;
        if ($port === 8000 && ! $useHttps) {
            return 80;
        }

        return $port;
    }

    public static function normalizeStoredPort(?int $port, bool $useHttps = false): ?int
    {
        if ($port === null) {
            return null;
        }
        if ($port === 8000 && ! $useHttps) {
            return 80;
        }

        return $port;
    }

    protected function isAllowedHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        // Allow hostnames for on-prem installs (no public DNS exfiltration guard here).
        return (bool) preg_match('/^[a-zA-Z0-9.\-]+$/', $host);
    }

    protected function assertSuccessful(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }
        throw new RuntimeException(
            "Hikvision {$context} failed HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500)
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function tryCapability(callable $fn): array
    {
        try {
            $payload = $fn();

            return ['supported' => true, 'payload' => $payload];
        } catch (\Throwable $e) {
            return [
                'supported' => false,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ];
        }
    }

    /**
     * @param  array<string, mixed>|null  $fingerprintCaps
     */
    protected function detectRemoteFingerprintEnrollment(?array $fingerprintCaps): bool
    {
        if ($fingerprintCaps === null || ! ($fingerprintCaps['supported'] ?? false)) {
            return false;
        }
        $payload = $fingerprintCaps['payload'] ?? [];
        $text = strtolower(json_encode($payload) ?: '');

        return str_contains($text, 'enroll') || str_contains($text, 'capture');
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    protected function resolveMaxResultsPerPage(?array $capabilities): int
    {
        if ($capabilities === null) {
            return 30;
        }
        $payload = $capabilities['events']['payload'] ?? $capabilities['payload'] ?? [];
        $max = (int) (
            data_get($payload, 'AcsEventCap.maxResults')
            ?? data_get($payload, 'GetAcsEventCap.maxResults')
            ?? data_get($payload, 'maxResults')
            ?? 30
        );

        return max(1, min($max > 0 ? $max : 30, 100));
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    protected function eventSearchDefaultsFromCapabilities(array $capabilities): array
    {
        $defaults = [];
        $payload = $capabilities['events']['payload'] ?? [];
        $attrs = data_get($payload, 'AcsEventCap.eventAttribute')
            ?? data_get($payload, 'GetAcsEventCap.eventAttribute')
            ?? null;
        if (is_array($attrs)) {
            $flat = array_map('strval', $attrs);
            if (in_array('attendance', $flat, true)) {
                $defaults['eventAttribute'] = 'attendance';
            }
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function normalizeEventRow(array $row): ?array
    {
        $employeeNo = trim((string) (
            $row['employeeNoString']
            ?? $row['employeeNo']
            ?? $row['cardNo']
            ?? ''
        ));
        $time = (string) ($row['time'] ?? $row['dateTime'] ?? '');
        if ($employeeNo === '' || $time === '') {
            return null;
        }

        return [
            'employee_no' => $employeeNo,
            'employee_name' => isset($row['name']) ? (string) $row['name'] : null,
            'punched_at' => $time,
            'attendance_status' => isset($row['attendanceStatus'])
                ? (string) $row['attendanceStatus']
                : null,
            'verification_method' => isset($row['currentVerifyMode'])
                ? (string) $row['currentVerifyMode']
                : (isset($row['verifyMode']) ? (string) $row['verifyMode'] : null),
            'card_no' => isset($row['cardNo']) ? (string) $row['cardNo'] : null,
            'serial_no' => isset($row['serialNo']) ? (string) $row['serialNo'] : null,
            'major' => isset($row['major']) ? (int) $row['major'] : null,
            'minor' => isset($row['minor']) ? (int) $row['minor'] : null,
            'raw' => $row,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseDeviceInfoXml(string $xml): array
    {
        $parsed = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA);
        if ($parsed === false) {
            return ['raw_xml' => $xml];
        }
        $device = $parsed->DeviceInfo ?? $parsed;
        $out = [];
        foreach ($device as $key => $value) {
            $out[(string) $key] = trim((string) $value);
        }

        return $out;
    }
}
