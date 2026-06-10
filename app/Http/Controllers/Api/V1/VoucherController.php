<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VoucherController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return Voucher::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('voucher_code', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%");
        });
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = $request->user();
        if ($user?->organization_id && empty($data['organization_id'])) {
            $data['organization_id'] = $user->organization_id;
        }
        $data['created_by'] = $user?->id;
        $model = Voucher::create($data);

        return response()->json($model, 201);
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $orgId = $request->user()?->organization_id;
        $voucherId = $updating ? (int) $request->route('voucher') : null;

        $data = $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'voucher_code' => [
                $updating ? 'sometimes' : 'required',
                'string',
                'max:50',
                Rule::unique('vouchers', 'voucher_code')
                    ->where(fn ($q) => $q->where('organization_id', $orgId))
                    ->ignore($voucherId),
            ],
            'voucher_kind' => ($updating ? 'sometimes|' : 'nullable|') . 'in:discount,payment',
            'name' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:2000',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0',
            'initial_balance' => 'nullable|numeric|min:0',
            'balance' => 'nullable|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_redemptions' => 'nullable|integer|min:1',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($data['voucher_code'])) {
            $data['voucher_code'] = strtoupper(trim($data['voucher_code']));
        }

        $kind = $data['voucher_kind'] ?? 'discount';
        $data['voucher_kind'] = $kind;

        if ($kind === 'payment') {
            $face = (float) ($data['initial_balance'] ?? $data['balance'] ?? $data['discount_value'] ?? 0);
            if (! $updating && $face <= 0) {
                throw ValidationException::withMessages([
                    'initial_balance' => 'Payment vouchers require a balance greater than zero.',
                ]);
            }
            $data['initial_balance'] = $face;
            $data['balance'] = array_key_exists('balance', $data)
                ? max(0, (float) $data['balance'])
                : $face;
            $data['discount_type'] = 'fixed';
            $data['discount_value'] = $face;
        } else {
            $discountType = $data['discount_type'] ?? 'fixed';
            $discountValue = (float) ($data['discount_value'] ?? 0);
            if ($discountType === 'percentage' && $discountValue > 100) {
                throw ValidationException::withMessages([
                    'discount_value' => 'Percentage discount cannot exceed 100.',
                ]);
            }
            $data['discount_type'] = $discountType;
            $data['discount_value'] = $discountValue;
            $data['initial_balance'] = 0;
            $data['balance'] = 0;
        }

        if (array_key_exists('min_order_amount', $data)) {
            $data['min_order_amount'] = max(0, (float) $data['min_order_amount']);
        }

        return $data;
    }
}
