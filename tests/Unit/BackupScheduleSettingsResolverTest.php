<?php

namespace Tests\Unit;

use App\Services\Backup\BackupScheduleSettingsResolver;
use Tests\TestCase;

class BackupScheduleSettingsResolverTest extends TestCase
{
    public function test_cron_expressions_match_frequency(): void
    {
        $this->assertSame(
            '15 * * * *',
            BackupScheduleSettingsResolver::cronExpression([
                'frequency' => 'hourly',
                'schedule_time' => '02:15',
            ]),
        );
        $this->assertSame(
            '0 */6 * * *',
            BackupScheduleSettingsResolver::cronExpression([
                'frequency' => 'every_6_hours',
                'schedule_time' => '02:00',
            ]),
        );
        $this->assertSame(
            '30 */12 * * *',
            BackupScheduleSettingsResolver::cronExpression([
                'frequency' => 'every_12_hours',
                'schedule_time' => '08:30',
            ]),
        );
        $this->assertSame(
            '0 2 * * *',
            BackupScheduleSettingsResolver::cronExpression([
                'frequency' => 'daily',
                'schedule_time' => '02:00',
            ]),
        );
    }

    public function test_frequency_label_describes_hourly(): void
    {
        $this->assertSame(
            'Every hour at :15',
            BackupScheduleSettingsResolver::frequencyLabel([
                'frequency' => 'hourly',
                'schedule_time' => '02:15',
            ]),
        );
        $this->assertSame(
            'Daily at 02:00',
            BackupScheduleSettingsResolver::frequencyLabel([
                'frequency' => 'daily',
                'schedule_time' => '02:00',
            ]),
        );
    }
}
