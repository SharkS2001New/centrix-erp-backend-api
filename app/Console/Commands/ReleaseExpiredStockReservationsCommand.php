<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use Illuminate\Console\Command;

class ReleaseExpiredStockReservationsCommand extends Command
{
    use HandlesInventory;

    protected $signature = 'erp:release-expired-stock-reservations';

    protected $description = 'Release stock reservations past their expiry time';

    public function handle(): int
    {
        $released = $this->releaseExpiredReservations();

        $this->info("Released {$released} expired stock reservation(s).");

        return self::SUCCESS;
    }
}
