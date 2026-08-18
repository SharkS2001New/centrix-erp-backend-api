<?php

namespace Tests\Unit\Background;

use App\Services\Background\InternalApiPaginator;
use Tests\TestCase;

class BackgroundPageProgressTest extends TestCase
{
    public function test_format_page_progress_includes_counts(): void
    {
        [$progress, $message, $processed, $total] = InternalApiPaginator::formatPageProgress(
            3,
            12,
            1500,
            6000,
            'rows',
        );

        $this->assertGreaterThan(10, $progress);
        $this->assertLessThanOrEqual(85, $progress);
        $this->assertSame(1500, $processed);
        $this->assertSame(6000, $total);
        $this->assertStringContainsString('page 3 of 12', $message);
        $this->assertStringContainsString('1,500 rows', $message);
    }
}
