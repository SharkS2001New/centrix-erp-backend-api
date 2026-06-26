<?php

namespace App\Services\Background;

use App\Models\Branch;
use App\Models\RouteModel;
use Illuminate\Support\Collection;

class CustomerCatalogExportMapper implements ListExportRowMapper
{
    /**
     * @param  list<array<string, mixed>>  $customers
     * @return list<array<string, mixed>>
     */
    public function mapBatch(array $customers): array
    {
        if ($customers === []) {
            return [];
        }

        $lookups = $this->loadLookups($customers);

        return array_map(
            fn (array $customer) => $this->mapOne($customer, $lookups),
            $customers,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     * @return array{
     *     routes: Collection<int|string, RouteModel>,
     *     branches: Collection<int|string, Branch>
     * }
     */
    protected function loadLookups(array $customers): array
    {
        $routeIds = $this->collectIds($customers, 'route_id');
        $branchIds = $this->collectIds($customers, 'branch_id');

        return [
            'routes' => $routeIds === []
                ? collect()
                : RouteModel::query()->whereIn('id', $routeIds)->get()->keyBy('id'),
            'branches' => $branchIds === []
                ? collect()
                : Branch::query()->whereIn('id', $branchIds)->get()->keyBy('id'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    protected function collectIds(array $rows, string $field): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row[$field] ?? null;
            if ($id !== null && $id !== '') {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array{
     *     routes: Collection<int|string, RouteModel>,
     *     branches: Collection<int|string, Branch>
     * }  $lookups
     * @return array<string, mixed>
     */
    protected function mapOne(array $customer, array $lookups): array
    {
        $route = $lookups['routes']->get($customer['route_id'] ?? null);
        $branch = $lookups['branches']->get($customer['branch_id'] ?? null);

        $isActive = array_key_exists('is_active', $customer)
            ? ! empty($customer['is_active'])
            : empty($customer['deleted_at']);

        $customerType = (string) ($customer['customer_type'] ?? 'debtor');

        return [
            'customer_num' => $customer['customer_num'] ?? '',
            'customer_name' => $customer['customer_name'] ?? '',
            'customer_type' => $customerType === 'route' ? 'Route' : 'Debtor',
            'phone_number' => $customer['phone_number'] ?? '',
            'additional_phone' => $customer['additional_phone'] ?? '',
            'town' => $customer['town'] ?? '',
            'route_name' => $customer['route_name'] ?? ($route?->route_name ?? ''),
            'credit_limit' => $customer['credit_limit'] ?? '',
            'current_balance' => $customer['current_balance'] ?? '',
            'kra_pin' => $customer['kra_pin'] ?? '',
            'terms_of_payment' => $customer['terms_of_payment'] ?? '',
            'branch_name' => $customer['branch_name'] ?? ($branch?->branch_name ?? ''),
            'latitude' => $customer['latitude'] ?? '',
            'longitude' => $customer['longitude'] ?? '',
            'is_active' => $isActive ? 'Yes' : 'No',
        ];
    }
}
