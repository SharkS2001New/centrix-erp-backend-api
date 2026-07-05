<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Support\PhoneNumber;

class CustomerPhoneLookup
{
    public function findByPhone(int $organizationId, string $phone): ?Customer
    {
        $normalized = PhoneNumber::normalize($phone);
        if ($normalized === null) {
            return null;
        }

        return Customer::query()
            ->with(['route'])
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($builder) use ($normalized) {
                $builder
                    ->whereRaw(
                        'REPLACE(REPLACE(REPLACE(phone_number, " ", ""), "-", ""), "+", "") = ?',
                        [$normalized],
                    )
                    ->orWhereRaw(
                        'REPLACE(REPLACE(REPLACE(additional_phone, " ", ""), "-", ""), "+", "") = ?',
                        [$normalized],
                    );
            })
            ->orderByDesc('id')
            ->first();
    }
}
