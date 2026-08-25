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

class GetExpenseSummaryTool implements AiToolInterface
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
        return 'get_expense_summary';
    }

    public function description(): string
    {
        return 'Get expense totals by category for a period with comparison to the prior equal-length period. '
            .'Use for "which expenses increased", "why are expenses higher", and operating cost analysis.';
    }

    public function parametersSchema(): array
    {
        return $this->aiPeriodParametersSchema();
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
            || $this->permissions->hasPermission($user, 'expenses.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view expenses.',
                'screens' => [['label' => 'Expenses', 'path' => '/expenses']],
            ];
        }

        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $branchId = isset($arguments['branch_id']) ? (int) $arguments['branch_id'] : null;

        $slice = $this->insightData->expenseSummarySlice($organization, $user, $from, $to, $branchId);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Expenses', 'path' => '/expenses'],
                ['label' => 'Profit & loss', 'path' => '/reports/profit-loss'],
            ],
        ]);
    }
}
