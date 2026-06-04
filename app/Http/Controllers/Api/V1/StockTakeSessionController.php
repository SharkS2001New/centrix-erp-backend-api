<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\StockTakeSession;

class StockTakeSessionController extends BaseResourceController
{
    protected function modelClass(): string { return StockTakeSession::class; }
}
