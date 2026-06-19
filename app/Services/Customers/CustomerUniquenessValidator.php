<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Validation\ValidationException;

class CustomerUniquenessValidator
{
    public function assertUnique(
        int $organizationId,
        ?string $phoneNumber,
        ?string $additionalPhone,
        ?string $kraPin,
        ?int $excludeCustomerNum = null,
    ): void {
        $phones = array_values(array_unique(array_filter([
            $this->normalizePhone($phoneNumber),
            $this->normalizePhone($additionalPhone),
        ])));

        foreach ($phones as $phone) {
            $exists = $this->phoneExists($organizationId, $phone, $excludeCustomerNum);
            if ($exists) {
                throw ValidationException::withMessages([
                    'phone_number' => ["Phone number [{$phone}] is already registered to another customer."],
                ]);
            }
        }

        $pin = $this->normalizePin($kraPin);
        if ($pin !== null) {
            $query = Customer::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereRaw('UPPER(TRIM(kra_pin)) = ?', [$pin]);

            if ($excludeCustomerNum !== null) {
                $query->where('customer_num', '!=', $excludeCustomerNum);
            }

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'kra_pin' => ["KRA PIN [{$pin}] is already registered to another customer."],
                ]);
            }
        }
    }

    protected function phoneExists(int $organizationId, string $phone, ?int $excludeCustomerNum): bool
    {
        $query = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($builder) use ($phone) {
                $builder
                    ->whereRaw('REPLACE(REPLACE(REPLACE(phone_number, " ", ""), "-", ""), "+", "") = ?', [$phone])
                    ->orWhereRaw('REPLACE(REPLACE(REPLACE(additional_phone, " ", ""), "-", ""), "+", "") = ?', [$phone]);
            });

        if ($excludeCustomerNum !== null) {
            $query->where('customer_num', '!=', $excludeCustomerNum);
        }

        return $query->exists();
    }

    protected function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '254') && strlen($digits) >= 12) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits;
    }

    protected function normalizePin(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $pin = strtoupper(trim($value));

        return $pin === '' ? null : $pin;
    }
}
