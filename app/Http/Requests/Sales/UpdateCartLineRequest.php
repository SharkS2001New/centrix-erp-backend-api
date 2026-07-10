<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCartLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => 'sometimes|numeric|min:0.001',
            'unit_price' => 'sometimes|numeric|min:0',
            'display_unit_price' => 'sometimes|nullable|numeric|min:0',
            'uom' => 'sometimes|nullable|string|max:45',
            'on_wholesale_retail' => 'sometimes|boolean',
            'product_vat' => 'sometimes|nullable|numeric|min:0',
            'discount_given' => 'sometimes|numeric|min:0',
            'update_no' => 'sometimes|integer|min:0',
        ];
    }
}
