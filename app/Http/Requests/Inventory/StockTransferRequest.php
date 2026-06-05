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
            'to_location' => 'required|in:shop,store,internal_use,donations,staff_consumption,charity,sample,production,display',
            'purpose' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $from = $this->input('from_location');
            $to = $this->input('to_location');
            if (in_array($to, ['shop', 'store'], true) && $from === $to) {
                $validator->errors()->add('to_location', 'From and to locations must differ.');
            }
        });
    }
}
