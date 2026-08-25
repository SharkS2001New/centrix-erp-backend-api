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

class CalculateScenarioTool implements AiToolInterface
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
        return 'calculate_scenario';
    }

    public function description(): string
    {
        return 'What-if scenario calculator using current period P&L baseline. Scenarios: price_increase, '
            .'sales_increase, supplier_cost_increase, discount_reduction. Pass percent_change (e.g. 5 for +5%). '
            .'Returns illustrative projected revenue/profit — label as estimate; volume may change in reality.';
    }

    public function parametersSchema(): array
    {
        $schema = $this->aiPeriodParametersSchema();
        $schema['properties']['scenario_type'] = [
            'type' => 'string',
            'enum' => ['price_increase', 'sales_increase', 'supplier_cost_increase', 'discount_reduction'],
        ];
        $schema['properties']['percent_change'] = [
            'type' => 'number',
            'description' => 'Percent change to apply (e.g. 5 for +5%, -2 for -2%).',
        ];
        $schema['required'] = ['scenario_type', 'percent_change'];

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
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.profit_loss.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to run scenario calculations.',
            ];
        }

        $scenarioType = str_replace('-', '_', trim((string) ($arguments['scenario_type'] ?? '')));
        $percent = (float) ($arguments['percent_change'] ?? 0);
        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);

        $slice = $this->insightData->scenarioCalculationSlice(
            $organization,
            $scenarioType,
            $percent,
            [
                'from_date' => $from,
                'to_date' => $to,
                'branch_id' => $arguments['branch_id'] ?? null,
            ],
        );

        if (! empty($slice['error'])) {
            return $slice;
        }

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
        ]);
    }
}
