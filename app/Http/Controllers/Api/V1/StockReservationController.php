<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\StockReservation;

class StockReservationController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return StockReservation::class;
    }
}
