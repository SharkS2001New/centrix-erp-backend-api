<?php

namespace App\Console\Commands;

use App\Models\MobileRepAttendanceSession;
use App\Services\Attendance\FieldRepAttendanceHrSync;
use App\Support\AppTimezone;
use Illuminate\Console\Command;

class SyncFieldRepHrAttendance extends Command
{
    protected $signature = 'erp:sync-field-rep-hr-attendance {--from=} {--to=}';

    protected $description = 'Backfill HR employee attendance from closed mobile field rep sessions';

    public function handle(FieldRepAttendanceHrSync $sync): int
    {
        $query = MobileRepAttendanceSession::query()
            ->whereNotNull('sign_out_at')
            ->orderBy('id');

        if ($from = $this->option('from')) {
            $query->whereDate('sign_in_at', '>=', $from);
        }

        if ($to = $this->option('to')) {
            $query->whereDate('sign_in_at', '<=', $to);
        }

        $synced = 0;
        $seen = [];

        foreach ($query->cursor() as $session) {
            $signIn = AppTimezone::normalize($session->sign_in_at);
            if (! $signIn) {
                continue;
            }

            $key = $session->user_id.'|'.$signIn->toDateString();
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            if ($sync->syncUserDay((int) $session->user_id, $signIn->toDateString())) {
                $synced++;
            }
        }

        $this->info("Synced {$synced} field rep day(s) into HR attendance.");

        return self::SUCCESS;
    }
}
