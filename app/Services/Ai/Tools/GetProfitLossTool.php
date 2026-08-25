<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\HasAiPeriodParameters;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetProfitLossTool implements AiToolInterface
{
    use HasAiPeriodParameters;
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_profit_loss';
    }

    public function description(): string
    {
        return 'Get operational profit & loss for a period: gross revenue, COGS, gross profit, expenses, net profit, '
            .'margins, prior-period comparison, top products by gross profit, and optional branch breakdown. '
            .'Use for gross/net profit, margin %, branch profitability, and "why is profit down". '
            .'Also link /reports/profit-loss.';
    }

    public function parametersSchema(): array
    {
        $schema = $this->aiPeriodParametersSchema();
        $schema['properties']['compare_previous_period'] = [
            'type' => 'boolean',
            'description' => 'Include equal-length prior period comparison. Default true.',
        ];
        $schema['properties']['include_branches'] = [
            'type' => 'boolean',
            'description' => 'Include gross profit by branch when no branch_id filter. Default false.',
        ];

        return $schema;
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
            || $this->permissions->hasPermission($user, 'reports.profit_loss.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'accounting.profit_loss.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view profit & loss.',
                'screens' => [['label' => 'Profit & loss', 'path' => '/reports/profit-loss']],
            ];
        }

        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $branchId = isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null;
        $compare = ! array_key_exists('compare_previous_period', $arguments)
            || filter_var($arguments['compare_previous_period'], FILTER_VALIDATE_BOOLEAN);
        $includeBranches = filter_var($arguments['include_branches'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $slice = $this->insightData->profitLossSlice(
            $organization,
            $user,
            $from,
            $to,
            $branchId,
            $compare,
            true,
            $includeBranches,
        );

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Profit & loss', 'path' => '/reports/profit-loss'],
                ['label' => 'P&L by product', 'path' => '/reports/profit-loss-by-product'],
            ],
        ]);
    }
}
