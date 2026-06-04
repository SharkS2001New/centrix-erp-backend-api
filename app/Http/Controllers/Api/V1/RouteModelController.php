<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\RouteModel;

class RouteModelController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return RouteModel::class;
    }
}
