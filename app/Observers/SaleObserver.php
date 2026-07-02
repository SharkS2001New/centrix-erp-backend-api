<?php

namespace App\Observers;

use App\Models\Sale;
use App\Services\Erp\ErpContext;
use App\Services\Sales\SaleRouteResolver;

class SaleObserver
{
    public function saving(Sale $sale): void
    {
        if ($sale->route_id || ! $sale->customer_num || ! $sale->organization_id) {
            return;
        }

        $organization = $sale->organization ?? $sale->organization()->first();
        if (! $organization) {
            return;
        }

        $gate = app(ErpContext::class)->gateForOrganization($organization);
        $routeId = app(SaleRouteResolver::class)->resolveFromCustomer(
            (int) $sale->customer_num,
            $gate,
            (string) ($sale->channel ?: 'backend'),
        );

        if ($routeId) {
            $sale->route_id = $routeId;
        }
    }
}
