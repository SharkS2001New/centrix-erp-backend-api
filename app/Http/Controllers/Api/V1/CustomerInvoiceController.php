<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CustomerInvoice;
use Illuminate\Http\Request;

class CustomerInvoiceController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return CustomerInvoice::class;
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery($request)->whereNull('deleted_at');

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
                    ->orWhere('customer_num', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return response()->json(
            $query->orderByDesc('invoice_date')->orderByDesc('id')->paginate($perPage),
        );
    }
}
