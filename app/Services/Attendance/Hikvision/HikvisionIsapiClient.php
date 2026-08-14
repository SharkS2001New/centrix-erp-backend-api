<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use App\Support\AppTimezone;
use Carbon\Carbon;
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
    /** DS-K1T UserInfo/CardInfo search typically rejects maxResults above 30. */
    public const ISAPI_SEARCH_PAGE_SIZE = 30;

    protected bool $lastRequestViaAgent = false;

    public function __construct(
        protected AttendanceClockDevice $device,
        protected ?HikvisionAgentBridge $agentBridge = null,
    ) {
    }

    public function lastRequestViaAgent(): bool
    {
        return $this->lastRequestViaAgent;
    }

    // ------------------------------------------------------------------
    // Connection / device info
    // ------------------------------------------------------------------

    public function ping(): bool
    {
        return $this->request('GET', '/ISAPI/System/deviceInfo')->successful();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDeviceInfo(): array
    {
        $response = $this->request('GET', '/ISAPI/System/deviceInfo');
        $this->assertSuccessful($response, 'deviceInfo');

        $contentType = strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'json')) {
            return $response->json() ?? [];
        }

        return $this->parseDeviceInfoXml($response->body);
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
        $wanted = max(1, (int) ($cond['maxResults'] ?? 30));
        $position = (int) ($cond['searchResultPosition'] ?? 0);
        $users = [];
        $total = 0;
        $hasMore = false;

        while (count($users) < $wanted) {
            $pageSize = min(self::ISAPI_SEARCH_PAGE_SIZE, $wanted - count($users));
            $pageCond = array_merge($cond, [
                'searchID' => $this->shortSearchId($cond['searchID'] ?? null),
                'searchResultPosition' => $position,
                'maxResults' => $pageSize,
            ]);
            $payload = $this->postIsapiSearch(
                '/ISAPI/AccessControl/UserInfo/Search?format=json',
                'UserInfoSearchCond',
                $pageCond,
            );
            $search = $payload['UserInfoSearch'] ?? $payload['UserInfoSearchCond'] ?? $payload;
            $list = $this->normalizeInfoList($search['UserInfo'] ?? $search['InfoList'] ?? []);
            $total = max($total, (int) ($search['totalMatches'] ?? $search['numOfMatches'] ?? count($list)));
            $users = array_merge($users, $list);
            $status = strtolower((string) ($search['responseStatusStrg'] ?? ''));
            $hasMore = $status === 'more';
            if ($list === [] || ! $hasMore) {
                break;
            }
            $position += max(1, count($list));
        }

        $users = $this->uniquePresentedUsers($users);

        return [
            'users' => array_slice($users, 0, $wanted),
            'total' => $total > 0 ? $total : count($users),
            'has_more' => $hasMore && count($users) >= $wanted,
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
        $pageCond = array_merge($cond, [
            'searchID' => $this->shortSearchId($cond['searchID'] ?? null),
            'searchResultPosition' => (int) ($cond['searchResultPosition'] ?? 0),
            'maxResults' => min(self::ISAPI_SEARCH_PAGE_SIZE, max(1, (int) ($cond['maxResults'] ?? 30))),
        ]);
        $payload = $this->postIsapiSearch(
            '/ISAPI/AccessControl/CardInfo/Search?format=json',
            'CardInfoSearchCond',
            $pageCond,
        );
        $search = $payload['CardInfoSearch'] ?? $payload;
        $list = $this->normalizeInfoList($search['CardInfo'] ?? $search['InfoList'] ?? []);

        return [
            'cards' => $list,
            'total' => (int) ($search['totalMatches'] ?? count($list)),
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
        $wanted = max(1, min(200, (int) ($cond['maxResults'] ?? 30)));
        $position = (int) ($cond['searchResultPosition'] ?? 0);
        $employeeNo = HikvisionEventNormalizer::usableString(
            $cond['employeeNo'] ?? null,
            $cond['employee_no'] ?? null,
            data_get($cond, 'EmployeeNoList.0.employeeNo'),
        );

        $attempts = [
            [
                'path' => '/ISAPI/AccessControl/FingerPrintInfo/Search?format=json',
                'key' => 'FingerPrintInfoSearchCond',
            ],
            [
                'path' => '/ISAPI/AccessControl/FingerPrint/Search?format=json',
                'key' => 'FingerPrintSearchCond',
            ],
            [
                'path' => '/ISAPI/AccessControl/FingerPrintUpload?format=json',
                'key' => 'FingerPrintCond',
            ],
        ];

        foreach ($attempts as $attempt) {
            try {
                $found = $this->searchFingerprintsOnPath(
                    $attempt['path'],
                    $attempt['key'],
                    $wanted,
                    $position,
                    $employeeNo,
                    $cond['EmployeeNoList'] ?? null,
                );
                if ($found['fingerprints'] !== [] || $found['total'] > 0) {
                    return $found;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return ['fingerprints' => [], 'total' => 0];
    }

    /**
     * @param  list<array<string, mixed>>|null  $employeeNoList
     * @return array{fingerprints: list<array>, total: int}
     */
    protected function searchFingerprintsOnPath(
        string $path,
        string $condKey,
        int $wanted,
        int $position,
        ?string $employeeNo,
        mixed $employeeNoList,
    ): array {
        $fingerprints = [];
        $total = 0;
        $searchId = $this->shortSearchId();

        while (count($fingerprints) < $wanted) {
            $pageSize = min(self::ISAPI_SEARCH_PAGE_SIZE, $wanted - count($fingerprints));
            $pageCond = [
                'searchID' => $searchId,
                'searchResultPosition' => $position,
                'maxResults' => $pageSize,
            ];
            if (is_array($employeeNoList) && $employeeNoList !== []) {
                $pageCond['EmployeeNoList'] = $employeeNoList;
            }
            if ($employeeNo !== null && $employeeNo !== '') {
                $pageCond['employeeNo'] = $employeeNo;
            }

            if ($condKey === 'FingerPrintCond') {
                $payload = $this->postJson($path, [$condKey => $this->filterFingerprintCond($pageCond)]);
            } else {
                $payload = $this->postIsapiSearch($path, $condKey, $pageCond);
            }

            $search = $payload['FingerPrintInfoSearch']
                ?? $payload['FingerPrintSearch']
                ?? $payload['FingerPrintList']
                ?? $payload['FingerPrintInfo']
                ?? $payload;
            $list = $this->normalizeInfoList(
                $search['FingerPrintInfo']
                ?? $search['FingerPrintList']
                ?? $search['InfoList']
                ?? $search['MatchList']
                ?? []
            );
            $list = array_map(fn (array $row) => $this->presentFingerprintInfo($row), $list);
            $total = max($total, (int) ($search['totalMatches'] ?? $search['numOfMatches'] ?? count($list)));
            $fingerprints = array_merge($fingerprints, $list);
            $status = strtolower((string) ($search['responseStatusStrg'] ?? ''));
            if ($list === [] || $status !== 'more') {
                break;
            }
            $position += max(1, count($list));
        }

        return [
            'fingerprints' => array_slice($fingerprints, 0, $wanted),
            'total' => $total > 0 ? $total : count($fingerprints),
        ];
    }

    /**
     * @param  array<string, mixed>  $cond
     * @return array<string, mixed>
     */
    protected function filterFingerprintCond(array $cond): array
    {
        $keep = [
            'searchID' => $this->shortSearchId(isset($cond['searchID']) ? (string) $cond['searchID'] : null),
            'searchResultPosition' => (int) ($cond['searchResultPosition'] ?? 0),
            'maxResults' => min(self::ISAPI_SEARCH_PAGE_SIZE, max(1, (int) ($cond['maxResults'] ?? 30))),
        ];
        $employeeNo = HikvisionEventNormalizer::usableString($cond['employeeNo'] ?? null);
        if ($employeeNo !== null) {
            $keep['employeeNo'] = $employeeNo;
        }

        return $keep;
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
        $cond = array_merge(
            [
                'major' => 0,
                'minor' => 0,
            ],
            $cond,
        );
        $body = ['AcsEventTotalNumCond' => $cond];
        $payload = $this->postJson('/ISAPI/AccessControl/AcsEventTotalNum?format=json', $body);

        return (int) (
            $payload['AcsEventTotalNum']['totalNum']
            ?? $payload['AcsEventTotalNum']['TotalNum']
            ?? 0
        );
    }

    /**
     * Hikvision AcsEvent datetime: no milliseconds, never trailing Z (devices reject it).
     */
    public static function formatAcsEventDateTime(\DateTimeInterface $dt, bool $withOffset = true): string
    {
        $local = Carbon::parse($dt)->timezone(AppTimezone::name());
        $formatted = $local->format($withOffset ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s');

        return str_replace('Z', '+00:00', $formatted);
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
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);
        if ($from->gt($to)) {
            $from = $to->copy()->subDays(2);
        }

        $lastError = null;
        $gotResponse = false;
        foreach ($this->acsEventCondCandidates($from, $to, $capabilities) as $baseCond) {
            try {
                $events = $this->fetchAccessEventsWithCond($from, $to, $maxResults, $capabilities, $baseCond);
                $gotResponse = true;
                if ($events !== []) {
                    return $events;
                }
            } catch (\Throwable $e) {
                $lastError = $e;
                if (! $this->isRetryableIsapiBadParameters($e)) {
                    throw $e;
                }
            }
        }

        if (! $gotResponse && $lastError !== null) {
            throw $lastError;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $baseCond
     * @param  array<string, mixed>|null  $capabilities
     * @return list<array<string, mixed>>
     */
    protected function fetchAccessEventsWithCond(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        int $maxResults,
        ?array $capabilities,
        array $baseCond,
    ): array {
        $maxPage = $this->resolveMaxResultsPerPage($capabilities);
        $searchId = $this->shortSearchId();
        $position = 0;
        $events = [];

        do {
            $pageMax = min($maxPage, max(1, $maxResults - count($events)));
            $payload = $this->postAcsEventSearch(
                $from,
                $to,
                $searchId,
                $position,
                $pageMax,
                $capabilities,
                $baseCond,
            );
            $acs = $payload['response']['AcsEvent'] ?? $payload['response'];
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

    /**
     * DS-K1T terminals require major/minor. eventAttribute and timezone offsets vary by firmware.
     *
     * @param  array<string, mixed>|null  $capabilities
     * @param  array<string, mixed>|null  $resolvedCond
     * @return array{response: array<string, mixed>, cond: array<string, mixed>}
     */
    protected function postAcsEventSearch(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        string $searchId,
        int $position,
        int $maxResults,
        ?array $capabilities,
        ?array $resolvedCond,
    ): array {
        $candidates = $resolvedCond !== null
            ? [$resolvedCond]
            : $this->acsEventCondCandidates($from, $to, $capabilities);

        $lastError = null;
        foreach ($candidates as $baseCond) {
            $cond = array_merge($baseCond, [
                'searchID' => $searchId,
                'searchResultPosition' => $position,
                'maxResults' => $maxResults,
            ]);
            try {
                $response = $this->postJson('/ISAPI/AccessControl/AcsEvent?format=json', [
                    'AcsEventCond' => $cond,
                ]);

                return ['response' => $response, 'cond' => $baseCond];
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($resolvedCond !== null || ! $this->isRetryableIsapiBadParameters($e)) {
                    throw $e;
                }
            }
        }

        throw $lastError ?? new RuntimeException('Hikvision AcsEvent search failed.');
    }

    /**
     * @param  array<string, mixed>|null  $capabilities
     * @return list<array<string, mixed>>
     */
    protected function acsEventCondCandidates(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?array $capabilities,
    ): array {
        $capabilityFilters = $this->eventSearchDefaultsFromCapabilities($capabilities ?? []);
        $offsetTimes = [
            'startTime' => self::formatAcsEventDateTime($from, true),
            'endTime' => self::formatAcsEventDateTime($to, true),
        ];
        $naiveTimes = [
            'startTime' => self::formatAcsEventDateTime($from, false),
            'endTime' => self::formatAcsEventDateTime($to, false),
        ];

        $withAttr = array_merge(['major' => 0, 'minor' => 0], $capabilityFilters);
        $fingerprint = ['major' => 5, 'minor' => 75];
        $card = ['major' => 5, 'minor' => 1];
        $allAuth = ['major' => 5, 'minor' => 0];
        $unfiltered = ['major' => 0, 'minor' => 0];

        $candidates = [];
        foreach ([$offsetTimes, $naiveTimes] as $times) {
            $candidates[] = array_merge($times, $fingerprint);
            $candidates[] = array_merge($times, $card);
            $candidates[] = array_merge($times, $allAuth);
            $candidates[] = array_merge($times, $unfiltered);
            $candidates[] = array_merge($times, $withAttr);
        }

        $unique = [];
        $seen = [];
        foreach ($candidates as $cond) {
            $key = json_encode($cond);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $cond;
        }

        return $unique;
    }

    protected function isRetryableIsapiBadParameters(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'badparameters')
            || str_contains($msg, 'invalid content')
            || str_contains($msg, '0x60000001');
    }

    /**
     * Hikvision searchID is often limited to 16 alphanumeric characters.
     */
    protected function shortSearchId(?string $given = null): string
    {
        $raw = $given !== null && $given !== ''
            ? $given
            : (string) Str::uuid();
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $raw) ?: '1';

        return substr($clean, 0, 16);
    }

    /**
     * @param  array<string, mixed>  $cond
     * @return array<string, mixed>
     */
    protected function filterSearchCond(array $cond): array
    {
        $keep = [];
        foreach ([
            'searchID',
            'searchResultPosition',
            'maxResults',
            'EmployeeNoList',
            'employeeNo',
            'fuzzySearch',
        ] as $key) {
            if (! array_key_exists($key, $cond)) {
                continue;
            }
            $value = $cond[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $keep[$key] = $value;
        }

        $keep['searchID'] = $this->shortSearchId(isset($keep['searchID']) ? (string) $keep['searchID'] : null);
        $keep['searchResultPosition'] = (int) ($keep['searchResultPosition'] ?? 0);
        $keep['maxResults'] = min(
            self::ISAPI_SEARCH_PAGE_SIZE,
            max(1, (int) ($keep['maxResults'] ?? self::ISAPI_SEARCH_PAGE_SIZE)),
        );

        return $keep;
    }

    /**
     * POST a UserInfo/CardInfo-style search, retrying a minimal cond if firmware
     * rejects UUID searchIDs, maxResults>30, or optional filters.
     *
     * @param  array<string, mixed>  $cond
     * @return array<string, mixed>
     */
    protected function postIsapiSearch(string $path, string $condKey, array $cond): array
    {
        $full = $this->filterSearchCond($cond);
        $minimal = [
            'searchID' => $full['searchID'],
            'searchResultPosition' => $full['searchResultPosition'],
            'maxResults' => $full['maxResults'],
        ];
        if (isset($full['EmployeeNoList'])) {
            $minimal['EmployeeNoList'] = $full['EmployeeNoList'];
        }
        if (isset($full['employeeNo'])) {
            $minimal['employeeNo'] = $full['employeeNo'];
        }
        $fallbackId = [
            'searchID' => '1',
            'searchResultPosition' => $full['searchResultPosition'],
            'maxResults' => $full['maxResults'],
        ];

        $candidates = [];
        foreach ([$full, $minimal, $fallbackId] as $candidate) {
            $key = json_encode($candidate);
            if (isset($candidates[$key])) {
                continue;
            }
            $candidates[$key] = $candidate;
        }

        $lastError = null;
        foreach ($candidates as $candidate) {
            try {
                return $this->postJson($path, [$condKey => $candidate]);
            } catch (\Throwable $e) {
                $lastError = $e;
                if (! $this->isRetryableIsapiBadParameters($e)) {
                    throw $e;
                }
            }
        }

        throw $lastError ?? new RuntimeException('Hikvision search failed.');
    }

    /**
     * @param  mixed  $list
     * @return list<array<string, mixed>>
     */
    protected function normalizeInfoList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        if (
            isset($list['employeeNo'])
            || isset($list['EmployeeNo'])
            || isset($list['employeeNoString'])
            || isset($list['cardNo'])
            || isset($list['fingerPrintID'])
        ) {
            $list = [$list];
        }

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * @param  list<array<string, mixed>>  $users
     * @return list<array<string, mixed>>
     */
    protected function uniquePresentedUsers(array $users): array
    {
        $seen = [];
        $out = [];
        foreach ($users as $user) {
            if (! is_array($user)) {
                continue;
            }
            $user = $this->presentUserInfo($user);
            $key = (string) ($user['employeeNo'] ?? '');
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }
            $out[] = $user;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    protected function presentUserInfo(array $user): array
    {
        $no = HikvisionEventNormalizer::usableString(
            $user['employeeNo'] ?? null,
            $user['employeeNoString'] ?? null,
            $user['EmployeeNo'] ?? null,
        );
        if ($no !== null) {
            $user['employeeNo'] = $no;
        }

        $fpBlock = is_array($user['fingerPrint'] ?? null) ? $user['fingerPrint'] : (
            is_array($user['FingerPrint'] ?? null) ? $user['FingerPrint'] : []
        );
        $fpCount = $this->firstNumericCount(
            $user['numOfFP'] ?? null,
            $user['NumOfFP'] ?? null,
            $user['numOfFingerPrint'] ?? null,
            $user['NumOfFingerPrint'] ?? null,
            $user['fingerPrintNum'] ?? null,
            $fpBlock['num'] ?? null,
            $fpBlock['count'] ?? null,
            $fpBlock['numOfFP'] ?? null,
        );
        if ($fpCount !== null) {
            $user['numOfFP'] = $fpCount;
        }

        $cardCount = $this->firstNumericCount(
            $user['numOfCard'] ?? null,
            $user['NumOfCard'] ?? null,
            $user['cardNum'] ?? null,
        );
        if ($cardCount !== null) {
            $user['numOfCard'] = $cardCount;
        }

        $faceCount = $this->firstNumericCount(
            $user['numOfFace'] ?? null,
            $user['NumOfFace'] ?? null,
            $user['faceNum'] ?? null,
        );
        if ($faceCount !== null) {
            $user['numOfFace'] = $faceCount;
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function presentFingerprintInfo(array $row): array
    {
        $no = HikvisionEventNormalizer::usableString(
            $row['employeeNo'] ?? null,
            $row['employeeNoString'] ?? null,
            $row['EmployeeNo'] ?? null,
        );
        if ($no !== null) {
            $row['employeeNo'] = $no;
        }
        $fingerId = $row['fingerPrintID'] ?? $row['FingerPrintID'] ?? $row['fingerID'] ?? null;
        if ($fingerId !== null && is_numeric($fingerId)) {
            $row['fingerPrintID'] = (int) $fingerId;
        }

        return $row;
    }

    protected function firstNumericCount(mixed ...$values): ?int
    {
        foreach ($values as $value) {
            if ($value === null || $value === '' || ! is_numeric($value)) {
                continue;
            }

            return max(0, (int) $value);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function getJson(string $path): array
    {
        $response = $this->request('GET', $path);
        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function postJson(string $path, array $body): array
    {
        $response = $this->request('POST', $path, $body);
        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function putJson(string $path, array $body): array
    {
        $response = $this->request('PUT', $path, $body);
        $this->assertSuccessful($response, $path);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function request(string $method, string $path, ?array $body = null): HikvisionIsapiResponse
    {
        $path = '/'.ltrim($path, '/');
        $bridge = $this->agentBridge;

        if ($bridge !== null && $bridge->shouldUseAgent($this->device)) {
            $this->lastRequestViaAgent = true;

            return $bridge->executeViaAgent($this->device, $method, $path, $body);
        }

        try {
            $response = $this->requestDirect($method, $path, $body);
            $this->lastRequestViaAgent = false;

            return $response;
        } catch (\Throwable $e) {
            if ($bridge !== null && $bridge->isConnectionError($e) && $bridge->isAgentOnline($this->device)) {
                $this->lastRequestViaAgent = true;

                return $bridge->executeViaAgent($this->device, $method, $path, $body);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    protected function requestDirect(string $method, string $path, ?array $body = null): HikvisionIsapiResponse
    {
        $url = $this->url($path);
        $pending = $this->http();
        $method = strtoupper($method);

        $response = match ($method) {
            'GET' => $pending->get($url),
            'POST' => $pending->asJson()->post($url, $body ?? []),
            'PUT' => $pending->asJson()->put($url, $body ?? []),
            'DELETE' => $pending->delete($url),
            default => throw new RuntimeException("Unsupported ISAPI method: {$method}"),
        };

        return $this->toIsapiResponse($response);
    }

    protected function toIsapiResponse(Response $response): HikvisionIsapiResponse
    {
        $headers = [];
        foreach ($response->headers() as $key => $values) {
            $headers[$key] = is_array($values) ? $values : [$values];
        }

        return new HikvisionIsapiResponse(
            $response->status(),
            $response->body(),
            $headers,
        );
    }

    protected function http(): PendingRequest
    {
        $username = (string) ($this->device->username ?: 'admin');
        $password = (string) ($this->device->plainPassword() ?? '');

        return Http::timeout(25)
            ->connectTimeout(8)
            ->retry(2, 500, function ($exception) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
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

    protected function assertSuccessful(HikvisionIsapiResponse|Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }
        $status = $response->status();
        $body = $response instanceof HikvisionIsapiResponse ? $response->body : $response->body();
        throw new RuntimeException(
            "Hikvision {$context} failed HTTP {$status}: ".mb_substr($body, 0, 500)
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
        $defaults = ['eventAttribute' => 'attendance'];
        $payload = $capabilities['events']['payload'] ?? [];
        $attrs = data_get($payload, 'AcsEventCap.eventAttribute')
            ?? data_get($payload, 'GetAcsEventCap.eventAttribute')
            ?? null;
        if ($attrs === null) {
            return $defaults;
        }

        $haystack = strtolower(is_array($attrs) ? implode(' ', array_map('strval', $attrs)) : (string) $attrs);
        if (! str_contains($haystack, 'attendance')) {
            unset($defaults['eventAttribute']);
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    protected function normalizeEventRow(array $row): ?array
    {
        $employeeNo = HikvisionEventNormalizer::usableString(
            $row['employeeNoString'] ?? null,
            $row['employeeNo'] ?? null,
            $row['cardNo'] ?? null,
        );
        $time = HikvisionEventNormalizer::usableString($row['time'] ?? null, $row['dateTime'] ?? null);
        if ($employeeNo === null || $time === null) {
            return null;
        }

        return HikvisionEventNormalizer::normalizeIncoming([
            'employee_no' => $employeeNo,
            'employee_name' => $row['name'] ?? null,
            'punched_at' => $time,
            'attendance_status' => $row['attendanceStatus'] ?? null,
            'verification_method' => $row['currentVerifyMode'] ?? $row['verifyMode'] ?? null,
            'card_no' => $row['cardNo'] ?? null,
            'serial_no' => $row['serialNo'] ?? null,
            'major' => $row['major'] ?? null,
            'minor' => $row['minor'] ?? null,
            'raw' => $row,
        ]);
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
