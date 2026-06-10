<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\LoyaltyCard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LoyaltyCardController extends HrOrgResourceController
{
    protected function modelClass(): string
    {
        return LoyaltyCard::class;
    }

    protected function applySearch($query, string $q): void
    {
        $query->where(function ($sub) use ($q) {
            $sub->where('card_number', 'like', "%{$q}%")
                ->orWhere('phone_number', 'like', "%{$q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$q}%"));
        });
    }

    public function index(Request $request)
    {
        $response = parent::index($request);
        $payload = $response->getData(true);
        $ids = collect($payload['data'] ?? [])->pluck('customer_num')->filter()->unique()->values();
        $customers = Customer::whereIn('customer_num', $ids)->get()->keyBy('customer_num');
        $payload['data'] = collect($payload['data'] ?? [])->map(function ($row) use ($customers) {
            $customer = $customers->get($row['customer_num'] ?? null);
            $row['customer_name'] = $customer?->customer_name;

            return $row;
        })->all();

        return response()->json($payload);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = $request->user();
        if ($user?->organization_id && empty($data['organization_id'])) {
            $data['organization_id'] = $user->organization_id;
        }
        $data['created_by'] = $user?->id;
        if (empty($data['card_number'])) {
            $data['card_number'] = $this->generateCardNumber((int) $data['organization_id']);
        }
        if (empty($data['phone_number'])) {
            $customer = Customer::find($data['customer_num']);
            $data['phone_number'] = $customer?->phone_number;
        }
        $model = LoyaltyCard::create($data);

        return response()->json($model->load('customer'), 201);
    }

    protected function validated(Request $request, bool $updating = false): array
    {
        $orgId = $request->user()?->organization_id;
        $cardId = $updating ? (int) $request->route('loyalty_card') : null;

        $data = $request->validate([
            'organization_id' => ($updating ? 'sometimes|' : '') . 'integer|exists:organizations,id',
            'customer_num' => [
                $updating ? 'sometimes' : 'required',
                'integer',
                Rule::exists('customers', 'customer_num')->where(
                    fn ($q) => $orgId ? $q->where('organization_id', $orgId) : $q,
                ),
            ],
            'card_number' => [
                'nullable',
                'string',
                'max:32',
                Rule::unique('loyalty_cards', 'card_number')
                    ->where(fn ($q) => $q->where('organization_id', $orgId))
                    ->ignore($cardId),
            ],
            'phone_number' => ($updating ? 'sometimes|' : 'nullable|') . 'string|max:45',
            'points_balance' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'issued_at' => 'nullable|date',
        ]);

        if (isset($data['card_number'])) {
            $data['card_number'] = strtoupper(trim($data['card_number']));
        }

        return $data;
    }

    protected function generateCardNumber(int $organizationId): string
    {
        do {
            $code = 'LC-' . strtoupper(Str::random(8));
        } while (LoyaltyCard::where('organization_id', $organizationId)->where('card_number', $code)->exists());

        return $code;
    }
}
