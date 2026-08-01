<?php

namespace Tests\Unit\Hospitality;

use App\Services\Hospitality\HospitalityReportService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HospitalityReportServiceTest extends TestCase
{
    #[Test]
    public function slugs_include_production_hospitality_reports(): void
    {
        $slugs = HospitalityReportService::slugs();

        foreach ([
            'hospitality-kpi-occupancy',
            'hospitality-room-revenue',
            'hospitality-fnb-by-outlet',
            'hospitality-fnb-by-hour',
            'hospitality-fnb-by-category',
            'hospitality-open-checks',
            'hospitality-voids',
            'hospitality-manager-flash',
            'hospitality-consumption-variance',
        ] as $slug) {
            $this->assertContains($slug, $slugs);
        }
    }
}
