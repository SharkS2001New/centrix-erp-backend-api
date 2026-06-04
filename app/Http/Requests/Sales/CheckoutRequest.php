<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_num' => 'nullable|integer',
            'status' => 'nullable|in:draft,held,booked,pending,processed,pending_payment,paid,completed,cancelled',
            'customer_num' => 'nullable|integer|exists:customers,customer_num',
            'customer_name_override' => 'nullable|string|max:500',
            'float_session_id' => 'nullable|integer',
            'payment_method_code' => 'nullable|string|max:45',
            'is_credit_sale' => 'nullable|boolean',
            'pay_now' => 'nullable|numeric|min:0',
            'total_vat' => 'nullable|numeric|min:0',
            'deduct_stock' => 'nullable|boolean',
            'submit_kra' => 'nullable|boolean',
        ];
    }
}
