<?php

namespace App\Console\Commands;

use App\Services\Hospitality\HospitalityPosRoomSaleService;
use Illuminate\Console\Command;

class ReleaseExpiredHotelRoomStaysCommand extends Command
{
    protected $signature = 'erp:release-expired-hotel-room-stays';

    protected $description = 'Mark Hotel POS prepaid room stays dirty when expected checkout time has passed (skips PMS folio stays)';

    public function handle(HospitalityPosRoomSaleService $service): int
    {
        $stats = $service->releaseExpiredStays();
        $this->info(sprintf('Released %d room(s) past checkout time.', $stats['released']));

        return self::SUCCESS;
    }
}
