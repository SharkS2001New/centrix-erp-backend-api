<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_code' => 'required|string|exists:products,product_code',
            'branch_id' => 'required|integer|exists:branches,id',
            'units_received' => 'required|numeric|min:0.001',
            'stock_location' => 'nullable|in:shop,store',
            'cost_price' => 'nullable|numeric|min:0',
            'invoice_number' => 'nullable|string|max:45',
            'lpo_no' => 'nullable|integer',
            'lpo_txn_id' => 'nullable|integer',
            'pack_qty' => 'nullable|numeric|min:0',
        ];
    }
}
