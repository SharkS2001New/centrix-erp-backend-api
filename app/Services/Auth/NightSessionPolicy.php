<?php

namespace App\Services\Auth;

use Carbon\CarbonInterface;

class NightSessionPolicy
{
    public function isActive(?CarbonInterface $at = null): bool
    {
        if (! config('security.night_session.enabled', true)) {
            return false;
        }

        $time = ($at ?? now(config('app.timezone', 'Africa/Nairobi')))->format('H:i');
        $start = (string) config('security.night_session.start', '23:00');
        $end = (string) config('security.night_session.end', '06:00');

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }

    public function idleMinutes(): int
    {
        return max(1, min(480, (int) config('security.night_session.idle_minutes', 30)));
    }

    /** @return array{enabled: bool, start: string, end: string, idle_minutes: int, timezone: string} */
    public function clientSettings(): array
    {
        return [
            'enabled' => (bool) config('security.night_session.enabled', true),
            'start' => (string) config('security.night_session.start', '23:00'),
            'end' => (string) config('security.night_session.end', '06:00'),
            'idle_minutes' => $this->idleMinutes(),
            'timezone' => (string) config('app.timezone', 'Africa/Nairobi'),
        ];
    }
}
