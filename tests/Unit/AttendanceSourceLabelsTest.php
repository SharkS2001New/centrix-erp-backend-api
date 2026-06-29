<?php

namespace Tests\Unit;

use App\Support\AttendanceSourceLabels;
use PHPUnit\Framework\TestCase;

class AttendanceSourceLabelsTest extends TestCase
{
    public function test_labels_are_short_and_friendly(): void
    {
        $this->assertSame('Mobile sales app', AttendanceSourceLabels::label('field_rep'));
        $this->assertSame('Premises (company phone)', AttendanceSourceLabels::label('company_mobile'));
        $this->assertSame('Premises (clock)', AttendanceSourceLabels::label('clock_device'));
        $this->assertSame('Manual entry', AttendanceSourceLabels::label('manual'));
    }

    public function test_login_channel_groups_premises_and_mobile_sales(): void
    {
        $this->assertSame('mobile_sales', AttendanceSourceLabels::channel('field_rep'));
        $this->assertSame('premises', AttendanceSourceLabels::channel('clock_device'));
        $this->assertSame('premises', AttendanceSourceLabels::channel('company_mobile'));
        $this->assertSame('manual', AttendanceSourceLabels::channel('manual'));

        $this->assertSame('Mobile sales app', AttendanceSourceLabels::channelLabel('field_rep'));
        $this->assertSame('Premises', AttendanceSourceLabels::channelLabel('clock_device'));
        $this->assertSame('Premises', AttendanceSourceLabels::channelLabel('company_mobile'));
        $this->assertSame('Manual entry', AttendanceSourceLabels::channelLabel('manual'));
    }
}
