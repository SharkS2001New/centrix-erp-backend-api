<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetSalesBriefTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_sales_brief';
    }

    public function description(): string
    {
        return 'Get a Centrix sales brief for a lookback window: totals vs prior period, daily sales, '
            .'top products, top customers, and unpaid snapshot. Prefer this for "how are sales doing" '
            .'over a multi-day period; use get_sales_summary for a single day/range total.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days to include (1–90). Default 7.',
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
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view sales data.',
            ];
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 7);
        $slice = $this->insightData->salesBriefSlice($organization, $user, $lookback);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Sales orders', 'path' => '/sales/orders'],
                ['label' => 'Reports', 'path' => '/reports'],
            ],
        ]);
    }
}
