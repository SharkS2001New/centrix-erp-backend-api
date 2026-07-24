<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceAbsentMaterializer;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MarkAttendanceAbsents extends Command
{
    protected $signature = 'erp:mark-attendance-absents
                            {--date= : Single date (Y-m-d). Defaults to yesterday when no range given.}
                            {--from= : Range start (Y-m-d)}
                            {--to= : Range end (Y-m-d)}
                            {--organization= : Limit to one organization id}';

    protected $description = 'Create absent attendance for scheduled workdays with no recorded attendance (never marks today/future)';

    public function handle(AttendanceAbsentMaterializer $materializer): int
    {
        $orgId = $this->option('organization') !== null && $this->option('organization') !== ''
            ? (int) $this->option('organization')
            : null;

        $from = $this->option('from');
        $to = $this->option('to');
        $date = $this->option('date');

        if ($from || $to) {
            $from = $from ? Carbon::parse($from)->toDateString() : Carbon::yesterday()->toDateString();
            $to = $to ? Carbon::parse($to)->toDateString() : $from;
            $result = $materializer->markRange($orgId, $from, $to);
        } else {
            $day = $date ? Carbon::parse($date)->toDateString() : null;
            $result = $materializer->markDate($orgId, $day);
        }

        $this->info(sprintf(
            'Marked %d absent attendance record(s); skipped %d.',
            $result['created_count'],
            $result['skipped_count'],
        ));

        if ($result['skipped_count'] > 0 && $this->output->isVerbose()) {
            foreach (array_slice($result['skipped'], 0, 20) as $row) {
                $this->warn(sprintf(
                    'employee %d on %s: %s',
                    $row['employee_id'],
                    $row['attendance_date'],
                    $row['reason'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
