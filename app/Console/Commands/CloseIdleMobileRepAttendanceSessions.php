<?php

namespace App\Console\Commands;

use App\Services\Sales\MobileFieldAttendanceService;
use Illuminate\Console\Command;

class CloseIdleMobileRepAttendanceSessions extends Command
{
    protected $signature = 'erp:close-idle-mobile-rep-attendance';

    protected $description = 'Close suspended or abandoned mobile rep attendance sessions at end of day';

    public function handle(MobileFieldAttendanceService $attendance): int
    {
        $closed = $attendance->closeIdleSessions();

        $this->info("Closed {$closed} idle mobile rep attendance session(s).");

        return self::SUCCESS;
    }
}
