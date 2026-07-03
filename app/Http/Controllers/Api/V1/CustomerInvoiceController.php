<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoice;
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
    protected function presentInvoice(CustomerInvoice $invoice): array
    {
        $payload = $invoice->toArray();
        $payload['customer_name'] = $invoice->customer?->customer_name;
        $payload['balance_due'] = max(
            0,
            round((float) $invoice->invoice_total - (float) $invoice->amount_paid, 2),
        );

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
            ->with($this->customerEagerLoad($request));

        if ($request->filled('customer_num')) {
            $query->where('customer_num', $request->input('customer_num'));
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
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
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (CustomerInvoice $invoice) => $this->presentInvoice($invoice)),
        );

        return response()->json($paginator);
    }

    public function show(Request $request, string $id)
    {
        $invoice = $this->findScopedModel($request, $id)
            ->load($this->customerEagerLoad($request));

        return response()->json($this->presentInvoice($invoice));
    }
}
