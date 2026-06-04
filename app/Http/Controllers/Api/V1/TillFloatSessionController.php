<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\TillFloatSession;

class TillFloatSessionController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return TillFloatSession::class;
    }
}
