<?php

namespace App\Services\Platform\Speed;

class PlatformSpeedHistogram
{
    /** @return list<int> */
    public function bounds(): array
    {
        $bounds = config('platform_speed.histogram_bounds_ms', [50, 100, 200, 400, 800, 1500, 3000, 5000, 10000]);

        return array_values(array_map('intval', is_array($bounds) ? $bounds : []));
    }

    /** @return array<string, int> zeroed bucket map keyed by upper bound or "inf" */
    public function emptyBuckets(): array
    {
        $out = [];
        foreach ($this->bounds() as $bound) {
            $out[(string) $bound] = 0;
        }
        $out['inf'] = 0;

        return $out;
    }

    /** @param  array<string, int>  $buckets */
    public function bucketIndex(int $ms, array &$buckets): void
    {
        foreach ($this->bounds() as $bound) {
            if ($ms <= $bound) {
                $key = (string) $bound;
                $buckets[$key] = (int) ($buckets[$key] ?? 0) + 1;

                return;
            }
        }
        $buckets['inf'] = (int) ($buckets['inf'] ?? 0) + 1;
    }

    /**
     * @param  array<string, int>  $buckets
     * @return array{p50: int|null, p95: int|null, p99: int|null}
     */
    public function percentiles(array $buckets, int $total): array
    {
        if ($total <= 0) {
            return ['p50' => null, 'p95' => null, 'p99' => null];
        }

        return [
            'p50' => $this->percentileAt($buckets, $total, 0.50),
            'p95' => $this->percentileAt($buckets, $total, 0.95),
            'p99' => $this->percentileAt($buckets, $total, 0.99),
        ];
    }

    /** @param  array<string, int>  $buckets */
    protected function percentileAt(array $buckets, int $total, float $p): ?int
    {
        $target = (int) ceil($total * $p);
        $seen = 0;
        $bounds = $this->bounds();
        $lastBound = $bounds === [] ? 10000 : (int) $bounds[array_key_last($bounds)];
        $ordered = [];
        foreach ($bounds as $bound) {
            $ordered[] = [(string) $bound, $bound];
        }
        $ordered[] = ['inf', $lastBound];

        foreach ($ordered as [$key, $value]) {
            $seen += (int) ($buckets[$key] ?? 0);
            if ($seen >= $target) {
                return (int) $value;
            }
        }

        return $lastBound;
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    public function merge(array $a, array $b): array
    {
        $out = $this->emptyBuckets();
        foreach ($out as $key => $_) {
            $out[$key] = (int) ($a[$key] ?? 0) + (int) ($b[$key] ?? 0);
        }

        return $out;
    }
}
