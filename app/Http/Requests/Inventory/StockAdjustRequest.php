<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'required|integer|exists:branches,id',
            'product_code' => 'required|string|exists:products,product_code',
            'stock_location' => 'required|in:shop,store',
            'quantity_change' => 'required|numeric|not_in:0',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
