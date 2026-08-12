<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = (int) ($this->user()?->organization_id ?? 0);

        return [
            'order_num' => 'nullable|integer',
            'status' => 'nullable|in:draft,held,booked,pending,unpaid,processed,pending_payment,paid,delivered,completed,cancelled',
            'customer_num' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'customer_num')->where(function ($query) use ($organizationId) {
                    $query->whereNull('deleted_at');
                    if ($organizationId > 0) {
                        $query->where('organization_id', $organizationId);
                    }
                }),
            ],
            'customer_name_override' => 'nullable|string|max:500',
            'customer_kra_pin' => 'nullable|string|max:45',
            'float_session_id' => 'nullable|integer',
            'sales_workspace' => 'nullable|in:pos,backoffice',
            'payment_method_code' => 'nullable|string|max:45',
            'is_credit_sale' => 'nullable|boolean',
            'payment_splits' => 'nullable|array|min:1',
            'payment_splits.*.method_code' => 'required|string|max:45',
            'payment_splits.*.amount' => 'required|numeric|min:0.01',
            'payment_splits.*.reference_number' => 'nullable|string|max:120',
            'payment_reference' => 'nullable|string|max:120',
            'payment_date' => 'nullable|date',
            'total_vat' => 'nullable|numeric|min:0',
            'deduct_stock' => 'nullable|boolean',
            'save_only' => 'nullable|boolean',
            'submit_kra' => 'nullable|boolean',
            'offline_order' => 'nullable|boolean',
            'client_sale_uuid' => 'nullable|string|max:64',
            'content_revision' => 'nullable|integer|min:1',
            'pos_device_id' => 'nullable|string|max:120',
            'device_identifier' => 'nullable|string|max:120',
            'pos_order_num' => 'nullable|integer|min:1',
            'pos_order_date' => 'nullable|date',
            'client_completed_at' => 'nullable|date',
            'checkout_latitude' => 'nullable|numeric|between:-90,90',
            'checkout_longitude' => 'nullable|numeric|between:-180,180',
            'discount_approval_reason' => 'nullable|string|max:500',
            'pay_now' => 'nullable|numeric|min:0',
            'order_change' => 'nullable|numeric|min:0',
            'payment_adjustments' => 'nullable|array',
            'payment_adjustments.*.method_code' => 'required|string|max:45',
            'payment_adjustments.*.amount' => 'required|numeric|min:0.01',
            'payment_adjustments.*.adjustment_type' => 'required|in:return,topup',
            'payment_adjustments.*.reference_number' => 'nullable|string|max:120',
        ];
    }
}
