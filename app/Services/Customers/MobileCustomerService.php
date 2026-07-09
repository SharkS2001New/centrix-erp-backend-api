<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserMobileOrderScopeService;
use App\Services\Erp\CapabilityGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileCustomerService
{
    public function __construct(
        protected CustomerUniquenessValidator $uniqueness,
        protected UserMobileOrderScopeService $mobileScope,
        protected CustomerRoutePolicy $customerRoutePolicy,
        protected UserAccessService $access,
    ) {}

    public function list(User $user, array $filters): array
    {
        $perPage = min((int) ($filters['per_page'] ?? 50), 200);
        $term = trim((string) ($filters['q'] ?? ''));

        $query = $this->scopedQuery($user)
            ->leftJoin('routes', 'customers.route_id', '=', 'routes.id')
            ->leftJoin('users', 'customers.created_by', '=', 'users.id')
            ->select([
                'customers.*',
                'routes.route_name',
                'users.username as created_by_username',
            ]);

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($builder) use ($like, $term) {
                $builder
                    ->where('customers.customer_name', 'like', $like)
                    ->orWhere('customers.phone_number', 'like', $like)
                    ->orWhere('customers.additional_phone', 'like', $like)
                    ->orWhere('customers.town', 'like', $like)
                    ->orWhere('customers.kra_pin', 'like', $like);
                if (ctype_digit($term)) {
                    $builder->orWhere('customers.customer_num', 'like', $like);
                }
            });
        }

        $rows = $query
            ->orderBy('customers.customer_name')
            ->limit($perPage)
            ->get();

        return [
            'data' => $rows->map(fn ($row) => $this->presentRow($row))->values()->all(),
            'meta' => [
                'count' => $rows->count(),
                'per_page' => $perPage,
            ],
        ];
    }

    public function show(User $user, int $customerNum): array
    {
        $row = $this->scopedQuery($user)
            ->leftJoin('routes', 'customers.route_id', '=', 'routes.id')
            ->leftJoin('users', 'customers.created_by', '=', 'users.id')
            ->select([
                'customers.*',
                'routes.route_name',
                'users.username as created_by_username',
            ])
            ->where('customers.customer_num', $customerNum)
            ->firstOrFail();

        return $this->presentRow($row);
    }

    public function store(User $user, array $data): array
    {
        $payload = $this->normalizePayload($data);
        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null) {
            $this->access->assertBranchInOrganization($user, (int) $payload['branch_id']);
            $this->access->assertBranchAccess($user, (int) $payload['branch_id']);
        }
        $this->mobileScope->assertCustomerPayload($user, $payload);
        $gate = app(CapabilityGate::class)->forOrganization($user->organization);
        $payload = $this->customerRoutePolicy->applyDistributionCustomerRules($payload, $gate);
        $this->uniqueness->assertUnique(
            (int) $user->organization_id,
            $payload['phone_number'] ?? null,
            $payload['additional_phone'] ?? null,
            $payload['kra_pin'] ?? null,
        );

        $customer = DB::transaction(function () use ($user, $payload) {
            $payload['customer_num'] = app(CustomerNumberAllocator::class)
                ->nextForOrganization((int) $user->organization_id);
            $payload['organization_id'] = (int) $user->organization_id;
            $payload['created_by'] = (int) $user->id;

            if (empty($payload['branch_id'])) {
                $payload['branch_id'] = (int) $user->branch_id;
            }

            return Customer::create($payload);
        });

        return $this->show($user, (int) $customer->customer_num);
    }

    public function update(User $user, int $customerNum, array $data): array
    {
        $customer = $this->scopedQuery($user)
            ->where('customer_num', $customerNum)
            ->firstOrFail();

        $payload = $this->normalizePayload($data, partial: true);
        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null) {
            $this->access->assertBranchInOrganization($user, (int) $payload['branch_id']);
            $this->access->assertBranchAccess($user, (int) $payload['branch_id']);
        }
        $this->mobileScope->assertCustomerPayload($user, $payload, $customer);
        $gate = app(CapabilityGate::class)->forOrganization($user->organization);
        $payload = $this->customerRoutePolicy->applyDistributionCustomerRules($payload, $gate, $customer);
        $this->uniqueness->assertUnique(
            (int) $user->organization_id,
            $payload['phone_number'] ?? $customer->phone_number,
            $payload['additional_phone'] ?? $customer->additional_phone,
            $payload['kra_pin'] ?? $customer->kra_pin,
            (int) $customer->customer_num,
        );

        $customer->update($payload);

        return $this->show($user, (int) $customer->customer_num);
    }

    protected function scopedQuery(User $user)
    {
        $query = Customer::query()
            ->whereNull('customers.deleted_at');

        $this->mobileScope->applyCustomerScope($query, $user);

        return $query;
    }

    /** @param array<string, mixed> $data */
    protected function normalizePayload(array $data, bool $partial = false): array
    {
        $allowed = [
            'branch_id',
            'customer_name',
            'customer_type',
            'phone_number',
            'additional_phone',
            'email',
            'town',
            'latitude',
            'longitude',
            'route_id',
            'kra_pin',
            'terms_of_payment',
            'credit_limit',
        ];

        $payload = [];
        foreach ($allowed as $field) {
            if ($partial && ! array_key_exists($field, $data)) {
                continue;
            }
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('customer_name', $payload)) {
            $payload['customer_name'] = trim((string) $payload['customer_name']);
        }

        foreach (['phone_number', 'additional_phone', 'kra_pin', 'terms_of_payment', 'town', 'email'] as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            $value = trim((string) ($payload[$field] ?? ''));
            $payload[$field] = $value === '' ? null : $value;
        }

        if (array_key_exists('customer_type', $payload)) {
            $type = (string) $payload['customer_type'];
            if (! in_array($type, ['debtor', 'route', 'regular'], true)) {
                throw ValidationException::withMessages([
                    'customer_type' => ['Customer type must be debtor, route, or regular.'],
                ]);
            }
            if (in_array($type, ['debtor', 'regular'], true)) {
                $payload['route_id'] = null;
            }
            if ($type === 'route' && empty($payload['route_id'])) {
                throw ValidationException::withMessages([
                    'route_id' => ['Route is required for route customers.'],
                ]);
            }
        }

        if (array_key_exists('route_id', $payload) && $payload['route_id'] !== null) {
            $payload['route_id'] = (int) $payload['route_id'];
            if ($payload['route_id'] <= 0) {
                $payload['route_id'] = null;
            }
        }

        if (array_key_exists('branch_id', $payload) && $payload['branch_id'] !== null) {
            $payload['branch_id'] = (int) $payload['branch_id'];
            if ($payload['branch_id'] <= 0) {
                $payload['branch_id'] = null;
            }
        }

        if (array_key_exists('credit_limit', $payload)) {
            $payload['credit_limit'] = max(0, (float) ($payload['credit_limit'] ?? 0));
        }

        $latProvided = array_key_exists('latitude', $payload);
        $lngProvided = array_key_exists('longitude', $payload);
        if ($latProvided || $lngProvided) {
            $lat = $payload['latitude'] ?? null;
            $lng = $payload['longitude'] ?? null;
            $latSet = $lat !== null && $lat !== '';
            $lngSet = $lng !== null && $lng !== '';

            if ($latSet xor $lngSet) {
                throw ValidationException::withMessages([
                    'latitude' => ['Latitude and longitude must both be provided together.'],
                    'longitude' => ['Latitude and longitude must both be provided together.'],
                ]);
            }

            if (! $latSet) {
                $payload['latitude'] = null;
                $payload['longitude'] = null;
            } else {
                $payload['latitude'] = round((float) $lat, 7);
                $payload['longitude'] = round((float) $lng, 7);
            }
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    protected function presentRow(object $row): array
    {
        return [
            'customer_num' => (int) $row->customer_num,
            'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
            'customer_name' => (string) $row->customer_name,
            'customer_type' => (string) ($row->customer_type ?? 'debtor'),
            'phone_number' => $row->phone_number,
            'additional_phone' => $row->additional_phone,
            'email' => $row->email,
            'town' => $row->town,
            'latitude' => $row->latitude !== null ? (float) $row->latitude : null,
            'longitude' => $row->longitude !== null ? (float) $row->longitude : null,
            'has_location' => $row->latitude !== null && $row->longitude !== null,
            'route_id' => $row->route_id !== null ? (int) $row->route_id : null,
            'route_name' => $row->route_name,
            'kra_pin' => $row->kra_pin,
            'terms_of_payment' => $row->terms_of_payment,
            'credit_limit' => round((float) ($row->credit_limit ?? 0), 2),
            'current_balance' => round((float) ($row->current_balance ?? 0), 2),
            'customer_status' => $row->customer_status,
            'createdBy' => $row->created_by_username ?? '',
        ];
    }
}
