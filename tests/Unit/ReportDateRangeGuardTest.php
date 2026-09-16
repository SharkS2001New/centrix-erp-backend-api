<?php

namespace Tests\Unit;

use App\Support\ReportDateRangeGuard;
use Tests\TestCase;

class ReportDateRangeGuardTest extends TestCase
{
    public function test_clamps_range_to_max_days(): void
    {
        $filters = ReportDateRangeGuard::enforce([
            'from_date' => '2025-01-01',
            'to_date' => '2025-12-31',
        ], 30, 90);

        $this->assertSame('2025-12-31', $filters['to_date']);
        $this->assertSame('2025-10-03', $filters['from_date']);
        $this->assertTrue($filters['date_range_clamped'] ?? false);
    }

    public function test_applies_default_when_missing(): void
    {
        $filters = ReportDateRangeGuard::enforce([], 14, 90);

        $this->assertTrue($filters['date_range_applied_default'] ?? false);
        $this->assertNotEmpty($filters['from_date']);
        $this->assertNotEmpty($filters['to_date']);
    }
}
