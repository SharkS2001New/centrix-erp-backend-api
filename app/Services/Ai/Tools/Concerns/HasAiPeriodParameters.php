<?php

namespace App\Services\Ai\Tools\Concerns;

trait HasAiPeriodParameters
{
    /** @return array<string, mixed> */
    protected function aiPeriodParametersSchema(bool $includeLookback = false): array
    {
        $props = [
            'relative_date' => [
                'type' => 'string',
                'enum' => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month'],
            ],
            'month' => [
                'type' => 'string',
                'description' => 'Calendar month name or number (e.g. august, 8). Pair with year.',
            ],
            'year' => [
                'type' => 'integer',
                'description' => 'Calendar year for month (e.g. 2026).',
            ],
            'year_month' => [
                'type' => 'string',
                'description' => 'YYYY-MM period (e.g. 2026-08).',
            ],
            'from_date' => [
                'type' => 'string',
                'description' => 'Period start YYYY-MM-DD.',
            ],
            'to_date' => [
                'type' => 'string',
                'description' => 'Period end YYYY-MM-DD.',
            ],
            'branch_id' => [
                'type' => 'integer',
                'description' => 'Optional branch filter.',
            ],
        ];

        if ($includeLookback) {
            $props['lookback_days'] = [
                'type' => 'integer',
                'description' => 'Days to include (1–90).',
            ];
        }

        return [
            'type' => 'object',
            'properties' => $props,
        ];
    }
}
