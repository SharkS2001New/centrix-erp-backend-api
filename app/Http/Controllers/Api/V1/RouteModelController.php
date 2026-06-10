<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\RouteModel;
use App\Models\Sale;
use App\Models\TemporaryCart;
use Illuminate\Support\Facades\DB;

class RouteModelController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return RouteModel::class;
    }

    public function destroy(string $id)
    {
        $route = RouteModel::where($this->routeKeyColumn(), $id)->firstOrFail();

        DB::transaction(function () use ($route) {
            Customer::where('route_id', $route->id)->update(['route_id' => null]);
            TemporaryCart::where('route_id', $route->id)->update(['route_id' => null]);
            Sale::where('route_id', $route->id)->update(['route_id' => null]);
            Driver::where('default_route_id', $route->id)->update(['default_route_id' => null]);
            $route->delete();
        });

        return response()->json(null, 204);
    }
}
