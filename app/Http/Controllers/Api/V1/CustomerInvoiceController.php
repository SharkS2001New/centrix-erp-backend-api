<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoice;
use App\Models\Sale;
use App\Services\Accounting\CustomerInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CustomerInvoiceController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return CustomerInvoice::class;
    }

    protected function scopesByBranch(): bool
    {
        return true;
    }

    protected function baseQuery(Request $request)
    {
        $query = CustomerInvoice::query();
        $user = $request->user();

        if (! $user) {
            return $query;
        }

        if (Schema::hasColumn('customer_invoices', 'organization_id')) {
            $this->access()->scopeOrganization($query, $user, 'organization_id', $request);
        } else {
            $this->access()->scopeOrganizationViaBranch($query, $user, 'branch_id', $request);
        }

        $this->access()->scopeBranchIfLimited($query, $user);

        return $query;
    }

    /** @return array<string, mixed> */
    protected function presentInvoice(CustomerInvoice $invoice, ?array $creditsBySale = null): array
    {
        $service = app(CustomerInvoiceService::class);
        $balances = $service->presentInvoiceBalances($invoice, $creditsBySale);

        $payload = $invoice->toArray();
        $payload['customer_name'] = $invoice->customer?->customer_name;
        $payload['amount_paid'] = $balances['amount_paid'];
        $payload['return_credit_total'] = $balances['return_credit_total'];
        $payload['balance_due'] = $balances['balance_due'];
        // Credit notes settle AR without cash — override stale DB payment_status.
        $payload['payment_status'] = $balances['payment_status'];

        return $payload;
    }

    /** @return array<string, mixed> */
    protected function customerEagerLoad(Request $request): array
    {
        $orgId = $this->access()->organizationId($request->user(), $request);

        return [
            'customer' => function ($query) use ($orgId) {
                $query->select('customer_num', 'customer_name', 'organization_id');
                if ($orgId) {
                    $query->where('customers.organization_id', $orgId);
                }
            },
        ];
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)
            ->whereNull('deleted_at')
            ->withSum('payments as paid_from_payments_sum', 'amount_paid')
            ->with($this->customerEagerLoad($request));

        // Full return cancels the sale (restock + cancel). Those AR rows must not
        // appear in Accounting — including legacy invoices that were never voided.
        $query->where(function ($outer) {
            $outer->whereNull('sale_id')
                ->orWhereHas('sale', function ($sale) {
                    $sale->where(function ($status) {
                        $status->whereNull('status')
                            ->orWhere('status', '!=', 'cancelled');
                    })->where('order_total', '>', 0.01);
                });
        });

        if ($request->filled('customer_num')) {
            $query->where('customer_num', $request->input('customer_num'));
        }

        if ($request->filled('payment_status')) {
            $wanted = (int) $request->input('payment_status');
            $cashSql = CustomerInvoiceService::cashCollectedSql('customer_invoices');
            $creditsSql = CustomerInvoiceService::creditsForInvoiceSaleSql('customer_invoices');
            $settledSql = "({$cashSql} + {$creditsSql})";
            $balanceSql = "GREATEST(0, ROUND(customer_invoices.invoice_total - {$settledSql}, 2))";

            if ($wanted === 2) {
                // Settled by cash and/or return credits — not stale DB "Paid".
                $query->whereRaw("{$balanceSql} <= 0.01");
            } elseif ($wanted === 1) {
                $query->whereRaw("{$balanceSql} > 0.01 AND {$settledSql} > 0.01");
            } elseif ($wanted === 0) {
                $query->whereRaw("{$balanceSql} > 0.01 AND {$settledSql} <= 0.01");
            }
        }

        if ($request->filled('from_date')) {
            $query->whereDate('invoice_date', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('invoice_date', '<=', $request->input('to_date'));
        }

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->filterableColumns(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = trim((string) $request->input('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('invoice_number', 'like', "%{$q}%")
                    ->orWhere('customer_num', 'like', "%{$q}%")
                    ->orWhereHas('customer', function ($customer) use ($q) {
                        $customer->where('customer_name', 'like', "%{$q}%");
                    });
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        $paginator = $query->orderByDesc('invoice_date')->orderByDesc('id')->paginate($perPage);
        $collection = $paginator->getCollection();
        $saleIds = $collection
            ->pluck('sale_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $creditsBySale = app(CustomerInvoiceService::class)->statementCreditsBySaleId($saleIds);
        $paginator->setCollection(
            $collection->map(
                fn (CustomerInvoice $invoice) => $this->presentInvoice($invoice, $creditsBySale),
            ),
        );

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        $invoice = $this->findScopedModel($request, $id)
            ->load($this->customerEagerLoad($request));

        $invoiceService = app(CustomerInvoiceService::class);
        $saleForSettle = $invoice->sale_id
            ? Sale::query()->find($invoice->sale_id)
            : null;
        if ($saleForSettle && $request->user()) {
            $invoice = $invoiceService->settleSaleTendersOntoInvoice(
                $invoice,
                $saleForSettle,
                $request->user(),
            );
        } else {
            $invoice = $invoiceService->syncPaidTotalsFromPayments($invoice);
        }

        $invoice->load($this->customerEagerLoad($request));
        $invoice->loadSum('payments as paid_from_payments_sum', 'amount_paid');

        $payload = $this->presentInvoice($invoice);

        // Full return left a live row — hide from Accounting.
        $sale = $invoice->sale_id
            ? \App\Models\Sale::query()->find($invoice->sale_id)
            : null;
        if (
            $sale
            && (
                in_array((string) $sale->status, ['cancelled'], true)
                || round((float) $sale->order_total, 2) <= 0.01
            )
        ) {
            app(CustomerInvoiceService::class)->voidForCancelledSale($sale, $request->user());

            return response()->json(['message' => 'Invoice voided — order was fully returned or cancelled.'], 404);
        }

        return response()->json($payload);
    }
}
