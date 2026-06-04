<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\StockTakeLine;

class StockTakeLineController extends BaseResourceController
{
    protected function modelClass(): string { return StockTakeLine::class; }
}
