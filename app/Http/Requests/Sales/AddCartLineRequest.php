<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class AddCartLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_code' => 'required|string|exists:products,product_code',
            'quantity' => 'required|numeric|min:0.001',
            'unit_price' => 'nullable|numeric|min:0',
            'uom' => 'nullable|string|max:45',
            'on_wholesale_retail' => 'nullable|boolean',
            'product_vat' => 'nullable|numeric|min:0',
        ];
    }
}
