<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class BranchStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_branch_id' => 'required|integer|exists:branches,id',
            'to_branch_id' => 'required|integer|exists:branches,id|different:from_branch_id',
            'product_code' => 'required|string|exists:products,product_code',
            'quantity' => 'required|numeric|min:0.001',
            'from_location' => 'required|in:shop,store',
            'to_location' => 'required|in:shop,store',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
