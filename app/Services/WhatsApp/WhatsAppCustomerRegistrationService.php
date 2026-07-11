<?php

namespace App\Services\WhatsApp;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Customers\CustomerNumberAllocator;
use App\Services\Customers\CustomerRoutePolicy;
use App\Services\Customers\CustomerUniquenessValidator;
use App\Services\Erp\CapabilityGate;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class WhatsAppCustomerRegistrationService
{
    public function __construct(
        protected CustomerUniquenessValidator $uniqueness,
        protected CustomerRoutePolicy $customerRoutePolicy,
    ) {}

    public function requiresRoute(CapabilityGate $gate): bool
    {
        return $this->customerRoutePolicy->routeCustomersOnly($gate);
    }

    /**
     * Active routes for WhatsApp pick-list (capped for message size).
     *
     * @return list<array{id: int, route_name: string}>
     */
    public function listRoutes(int $organizationId, ?int $branchId = null, int $limit = 20): array
    {
        $query = RouteModel::query()
            ->where('organization_id', $organizationId)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderBy('route_name');

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'route_name'])
            ->map(fn (RouteModel $route) => [
                'id' => (int) $route->id,
                'route_name' => (string) $route->route_name,
            ])
            ->all();
    }

    /**
     * Create a customer from WhatsApp self-registration.
     *
     * @param  array{
     *   customer_name: string,
     *   phone: string,
     *   town?: string|null,
     *   route_id?: int|null,
     *   branch_id?: int|null
     * }  $input
     * @return array{ok: true, customer: Customer}|array{ok: false, message: string}
     */
    public function register(
        ResolvedWhatsAppConfig $config,
        User $botUser,
        CapabilityGate $gate,
        array $input,
    ): array {
        $name = trim((string) ($input['customer_name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2) {
            return ['ok' => false, 'message' => 'Please reply with your shop or business name (at least 2 characters).'];
        }

        $phone = PhoneNumber::normalize((string) ($input['phone'] ?? ''));
        if ($phone === null) {
            return ['ok' => false, 'message' => 'We could not read your WhatsApp number. Please call the office to register.'];
        }

        $town = trim((string) ($input['town'] ?? ''));
        $town = $town !== '' && strtoupper($town) !== 'SKIP' ? $town : null;

        $branchId = $this->resolveBranchId($config, $botUser, isset($input['branch_id']) ? (int) $input['branch_id'] : null);
        if (! $branchId) {
            return ['ok' => false, 'message' => 'Registration is unavailable right now (no branch configured).'];
        }

        $payload = [
            'customer_name' => $name,
            'phone_number' => $phone,
            'town' => $town,
            'branch_id' => $branchId,
            'customer_type' => 'regular',
            'credit_limit' => 0,
            'route_id' => isset($input['route_id']) ? (int) $input['route_id'] : null,
        ];

        try {
            $payload = $this->customerRoutePolicy->applyDistributionCustomerRules($payload, $gate);
            $this->uniqueness->assertUnique(
                $config->organizationId,
                $payload['phone_number'],
                null,
                null,
            );

            $customer = DB::transaction(function () use ($config, $botUser, $payload) {
                $payload['customer_num'] = app(CustomerNumberAllocator::class)
                    ->nextForOrganization($config->organizationId);
                $payload['organization_id'] = $config->organizationId;
                $payload['created_by'] = (int) $botUser->id;

                return Customer::query()->create($payload);
            });

            return ['ok' => true, 'customer' => $customer->fresh(['route'])];
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return [
                'ok' => false,
                'message' => is_string($first) && $first !== ''
                    ? $first
                    : 'We could not complete registration with the details provided.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'ok' => false,
                'message' => 'Something went wrong while creating your account.',
            ];
        }
    }

    public function officeContactLine(int $organizationId, ?int $preferredBranchId = null): string
    {
        $phone = $this->officePhone($organizationId, $preferredBranchId);
        if ($phone === null) {
            return 'Please call our office during business hours, or wait for a team member to contact you.';
        }

        return "Please call our office on *{$phone}*, or wait for a team member to contact you.";
    }

    public function officePhone(int $organizationId, ?int $preferredBranchId = null): ?string
    {
        if ($preferredBranchId) {
            $branchPhone = Branch::query()
                ->where('organization_id', $organizationId)
                ->where('id', $preferredBranchId)
                ->value('branch_phone');
            $normalized = $this->displayPhone($branchPhone);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $hqPhone = Branch::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN LOWER(COALESCE(branch_type, '')) IN ('hq','head office','headoffice') THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->value('branch_phone');
        $normalized = $this->displayPhone($hqPhone);
        if ($normalized !== null) {
            return $normalized;
        }

        $org = Organization::query()->find($organizationId);
        foreach (['primary_tel', 'secondary_tel', 'addn_tel1', 'addn_tel2'] as $field) {
            $normalized = $this->displayPhone($org?->{$field} ?? null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    protected function resolveBranchId(ResolvedWhatsAppConfig $config, User $botUser, ?int $requested): ?int
    {
        if ($requested && $requested > 0) {
            $exists = Branch::query()
                ->where('organization_id', $config->organizationId)
                ->where('id', $requested)
                ->exists();
            if ($exists) {
                return $requested;
            }
        }

        if ($config->branchId) {
            return (int) $config->branchId;
        }

        if ($botUser->branch_id && (int) $botUser->organization_id === $config->organizationId) {
            return (int) $botUser->branch_id;
        }

        $first = Branch::query()
            ->where('organization_id', $config->organizationId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $first ? (int) $first : null;
    }

    protected function displayPhone(?string $value): ?string
    {
        $normalized = PhoneNumber::normalize($value);
        if ($normalized === null || strlen($normalized) < 9) {
            return null;
        }

        return $normalized;
    }
}
