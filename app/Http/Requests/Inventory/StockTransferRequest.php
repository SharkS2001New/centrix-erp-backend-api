<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockTransferRequest extends FormRequest
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
            'quantity' => 'required|numeric|min:0.001',
            'from_location' => 'required|in:shop,store',
            'to_location' => 'required|in:shop,store|different:from_location',
        ];
    }
}
