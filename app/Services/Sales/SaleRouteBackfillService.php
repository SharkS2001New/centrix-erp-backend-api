<?php

namespace App\Services\Sales;

use App\Models\Organization;
use App\Models\Sale;
use App\Services\Erp\ErpContext;

class SaleRouteBackfillService
{
    public function __construct(
        protected ErpContext $erp,
        protected SaleRouteResolver $resolver,
    ) {}

    public function syncOrganization(?Organization $organization, int $limit = 250): int
    {
        if (! $organization) {
            return 0;
        }

        $gate = $this->erp->gateForOrganization($organization);
        $sales = Sale::query()
            ->where('organization_id', $organization->id)
            ->whereNull('route_id')
            ->whereNotNull('customer_num')
            ->whereHas('customer', fn ($query) => $query->whereNotNull('route_id'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $updated = 0;
        foreach ($sales as $sale) {
            $routeId = $this->resolver->resolveFromCustomer(
                (int) $sale->customer_num,
                $gate,
                (string) ($sale->channel ?: 'backend'),
            );
            if (! $routeId) {
                continue;
            }

            $sale->forceFill(['route_id' => $routeId])->saveQuietly();
            $updated++;
        }

        return $updated;
    }
}
