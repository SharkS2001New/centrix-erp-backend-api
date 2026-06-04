<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\KraResponse;

class KraResponseController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return KraResponse::class;
    }
}
