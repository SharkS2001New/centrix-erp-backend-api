<?php

namespace Tests\Unit;

use App\Support\AttendanceSourceLabels;
use PHPUnit\Framework\TestCase;

class AttendanceSourceLabelsTest extends TestCase
{
    public function test_labels_are_short_and_friendly(): void
    {
        $this->assertSame('Field rep', AttendanceSourceLabels::label('field_rep'));
        $this->assertSame('Company phone', AttendanceSourceLabels::label('company_mobile'));
        $this->assertSame('Clock', AttendanceSourceLabels::label('clock_device'));
        $this->assertSame('Manual', AttendanceSourceLabels::label('manual'));
    }
}
