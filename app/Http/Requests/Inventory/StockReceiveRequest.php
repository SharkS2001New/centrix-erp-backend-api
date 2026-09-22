<?php

namespace App\Http\Requests\Inventory;

use App\Services\Erp\ErpContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Validator;

class StockReceiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
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

        if ($this->batchTrackingEnabled()) {
            $rules['batch_no'] = 'nullable|string|max:100';
            $rules['expiry_date'] = 'nullable|date';
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->batchTrackingEnabled()) {
                // Ignore client-supplied lot fields when the org flag is off.
                $this->merge([
                    'batch_no' => null,
                    'expiry_date' => null,
                ]);

                return;
            }

            $batch = trim((string) $this->input('batch_no', ''));
            $this->merge(['batch_no' => $batch !== '' ? $batch : null]);

            $expiry = $this->input('expiry_date');
            if ($expiry === '' || $expiry === null) {
                $this->merge(['expiry_date' => null]);
            }
        });
    }

    protected function batchTrackingEnabled(): bool
    {
        if (! Schema::hasColumn('stock_receipts', 'batch_no')) {
            return false;
        }

        $user = $this->user();
        if (! $user) {
            return false;
        }

        $gate = app(ErpContext::class)->gateForUser($user);
        $inventory = $gate->moduleSettings('inventory');

        return ($inventory['enable_receive_batch_tracking'] ?? false) === true
            || ($inventory['enable_receive_batch_tracking'] ?? false) === 1
            || ($inventory['enable_receive_batch_tracking'] ?? false) === '1';
    }
}
