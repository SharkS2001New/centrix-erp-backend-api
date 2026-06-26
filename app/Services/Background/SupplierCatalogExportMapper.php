<?php

namespace App\Services\Background;

class SupplierCatalogExportMapper implements ListExportRowMapper
{
    /**
     * @param  list<array<string, mixed>>  $suppliers
     * @return list<array<string, mixed>>
     */
    public function mapBatch(array $suppliers): array
    {
        return array_map(fn (array $supplier) => $this->mapOne($supplier), $suppliers);
    }

    /** @return array<string, mixed> */
    protected function mapOne(array $supplier): array
    {
        $contacts = $supplier['contacts'] ?? null;
        $contactSummary = '';
        if (is_array($contacts) && $contacts !== []) {
            $contactSummary = collect($contacts)
                ->map(function ($contact) {
                    if (! is_array($contact)) {
                        return '';
                    }
                    $label = trim((string) ($contact['label'] ?? ''));
                    $phone = trim((string) ($contact['phone'] ?? ''));
                    $email = trim((string) ($contact['email'] ?? ''));

                    return trim(implode(' · ', array_filter([$label, $phone, $email])));
                })
                ->filter()
                ->implode('; ');
        }

        return [
            'supplier_code' => $supplier['supplier_code'] ?? '',
            'supplier_name' => $supplier['supplier_name'] ?? '',
            'contact_person' => $supplier['contact_person'] ?? '',
            'phone' => $supplier['phone'] ?? '',
            'alternate_phone' => $supplier['alternate_phone'] ?? '',
            'email' => $supplier['email'] ?? '',
            'town' => $supplier['town'] ?? '',
            'tax_pin' => $supplier['tax_pin'] ?? '',
            'address' => $supplier['address'] ?? '',
            'current_balance' => $supplier['current_balance'] ?? '',
            'other_contacts' => $supplier['other_contacts'] ?? $contactSummary,
            'is_active' => ! empty($supplier['is_active']) ? 'Yes' : 'No',
        ];
    }
}
