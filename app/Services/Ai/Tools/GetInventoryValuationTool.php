<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetInventoryValuationTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_inventory_valuation';
    }

    public function description(): string
    {
        return 'Get inventory valuation: total cost value, retail value, SKU health counts, and top products '
            .'by cash tied up in stock. Use for "how much money in inventory", overstock/dead stock context, '
            .'and reorder capital planning.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'branch_id' => [
                    'type' => 'integer',
                    'description' => 'Optional branch filter.',
                ],
                'top_products_limit' => [
                    'type' => 'integer',
                    'description' => 'Number of top SKUs by cost value (5–30). Default 15.',
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
            || $this->permissions->hasPermission($user, 'reports.stock_valuation.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'inventory.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view inventory valuation.',
                'screens' => [['label' => 'Stock valuation', 'path' => '/reports/stock-valuation']],
            ];
        }

        $branchId = isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null;
        $topLimit = (int) ($arguments['top_products_limit'] ?? 15);

        $slice = $this->insightData->inventoryValuationSlice($organization, $user, $branchId, $topLimit);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Stock valuation', 'path' => '/reports/stock-valuation'],
                ['label' => 'Stock on hand', 'path' => '/reports/stock-on-hand'],
            ],
        ]);
    }
}
