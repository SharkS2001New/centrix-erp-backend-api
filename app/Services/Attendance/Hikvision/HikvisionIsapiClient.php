<?php

namespace App\Services\Attendance\Hikvision;

use App\Models\AttendanceClockDevice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minimal Hikvision ISAPI client for DS-K1T / fingerprint attendance terminals.
 * Pulls AccessController events (punches) over HTTP digest/basic auth.
 */
class HikvisionIsapiClient
{
    public function __construct(protected AttendanceClockDevice $device)
    {
    }

    /**
     * @return list<array{employee_no: string, punched_at: string, attendance_status: string|null, raw: array}>
     */
    public function fetchAccessEvents(\DateTimeInterface $from, \DateTimeInterface $to, int $maxResults = 100): array
    {
        $searchId = (string) Str::uuid();
        $position = 0;
        $events = [];

        do {
            $body = [
                'AcsEventCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => min(30, $maxResults - count($events)),
                    'major' => 5,
                    'startTime' => $from->format('Y-m-d\TH:i:sP'),
                    'endTime' => $to->format('Y-m-d\TH:i:sP'),
                ],
            ];

            $response = $this->http()
                ->asJson()
                ->post($this->url('/ISAPI/AccessControl/AcsEvent?format=json'), $body);

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Hikvision AcsEvent failed HTTP '.$response->status().': '.$response->body()
                );
            }

            $payload = $response->json();
            $list = $payload['AcsEvent']['InfoList']
                ?? $payload['AcsEvent']['infoList']
                ?? [];
            if (! is_array($list)) {
                $list = [];
            }

            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $employeeNo = trim((string) (
                    $row['employeeNoString']
                    ?? $row['employeeNo']
                    ?? $row['cardNo']
                    ?? ''
                ));
                $time = (string) ($row['time'] ?? $row['dateTime'] ?? '');
                if ($employeeNo === '' || $time === '') {
                    continue;
                }
                $events[] = [
                    'employee_no' => $employeeNo,
                    'punched_at' => $time,
                    'attendance_status' => isset($row['attendanceStatus'])
                        ? (string) $row['attendanceStatus']
                        : null,
                    'raw' => $row,
                ];
            }

            $matches = (int) ($payload['AcsEvent']['numOfMatches'] ?? count($list));
            $position += max(1, $matches);
            if ($matches < 1 || count($events) >= $maxResults || count($list) < 1) {
                break;
            }
        } while (count($events) < $maxResults);

        return $events;
    }

    public function ping(): bool
    {
        $response = $this->http()->get($this->url('/ISAPI/System/deviceInfo'));

        return $response->successful();
    }

    protected function http(): PendingRequest
    {
        $username = (string) ($this->device->username ?: 'admin');
        $password = (string) ($this->device->plainPassword() ?? '');

        return Http::timeout(20)
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
        $scheme = $this->device->use_https ? 'https' : 'http';
        $port = (int) ($this->device->port ?: ($this->device->use_https ? 443 : 80));
        $path = '/'.ltrim($path, '/');

        return "{$scheme}://{$host}:{$port}{$path}";
    }
}
