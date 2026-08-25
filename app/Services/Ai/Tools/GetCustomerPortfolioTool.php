<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetCustomerPortfolioTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_customer_portfolio';
    }

    public function description(): string
    {
        return 'Customer portfolio intelligence: top customers by revenue, inactive customers (no recent purchases), '
            .'declining purchase trends, and high credit-limit utilization. Use for churn/at-risk/follow-up lists '
            .'across customers — not just one customer (use run_insight customer_360 for a single customer).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Revenue comparison window (14–180). Default 90.',
                ],
                'inactive_days' => [
                    'type' => 'integer',
                    'description' => 'Days without purchase to flag inactive (14–365). Default 45.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max rows per list (5–50). Default 20.',
                ],
            ],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }
        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'customers.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view customer portfolio data.',
            ];
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 90);
        $inactiveDays = (int) ($arguments['inactive_days'] ?? 45);
        $limit = (int) ($arguments['limit'] ?? 20);

        $slice = $this->insightData->customerPortfolioSlice(
            $organization,
            $user,
            $lookback,
            $inactiveDays,
            $limit,
        );

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Customers', 'path' => '/customers'],
                ['label' => 'AR aging', 'path' => '/reports/ar-aging'],
            ],
        ]);
    }
}
