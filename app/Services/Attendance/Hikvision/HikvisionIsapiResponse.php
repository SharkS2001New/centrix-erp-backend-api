<?php

namespace App\Services\Attendance\Hikvision;

/**
 * Normalized ISAPI HTTP response (direct or via LAN agent).
 */
class HikvisionIsapiResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
        public bool $viaAgent = false,
    ) {
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower((string) $key) === $lower) {
                return is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return [];
        }
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
