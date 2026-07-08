<?php

namespace App\Observers;

use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Erp\ErpContext;
use App\Services\Sales\SaleRouteResolver;

use App\Support\EffectiveSaleDate;

class SaleObserver
{
    public function saving(Sale $sale): void
    {
        if (EffectiveSaleDate::columnExists()) {
            $sale->effective_sale_date = EffectiveSaleDate::resolve(
                $sale->completed_at ? \Carbon\Carbon::parse($sale->completed_at) : null,
                $sale->created_at ? \Carbon\Carbon::parse($sale->created_at) : null,
            );
        }

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

    public function created(Sale $sale): void
    {
        $this->syncCustomerInvoice($sale);
    }

    public function updated(Sale $sale): void
    {
        if ($sale->wasChanged(['customer_num', 'order_total', 'amount_paid', 'payment_status', 'total_vat'])) {
            $this->syncCustomerInvoice($sale);
        }
    }

    protected function syncCustomerInvoice(Sale $sale): void
    {
        if (! $sale->customer_num || (float) $sale->order_total <= 0.01) {
            return;
        }

        $userId = $sale->cashier_id ?? $sale->created_by;
        if (! $userId) {
            return;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return;
        }

        app(CustomerInvoiceService::class)->ensureForSale($sale, $user);
    }
}
