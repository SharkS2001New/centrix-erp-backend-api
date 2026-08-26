<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\HasAiPeriodParameters;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Ai\Tools\Concerns\ResolvesAiUsers;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetExpenseSummaryTool implements AiToolInterface
{
    use HasAiPeriodParameters;
    use ResolvesAiToolOrganization;
    use ResolvesAiUsers;

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
        return 'Get expense totals by category for a period, optionally filtered to one person. '
            .'Centrix attributes accounting expenses via recorded_by and mobile route expenses via user_id — '
            .'NEVER say expenses are only organization-level or cannot be shown per salesperson. '
            .'For "CHEGE\'s expenses", "expenses done by Jane", or a named rep: ALWAYS pass user_name or username. '
            .'Also pass relative_date=yesterday/today when asked. Returns by_category, expense_lines, and mobile_route_expenses.';
    }

    public function parametersSchema(): array
    {
        $schema = $this->aiPeriodParametersSchema();
        $schema['properties']['user_name'] = [
            'type' => 'string',
            'description' => 'Filter expenses recorded by this user (full name or username).',
        ];
        $schema['properties']['username'] = [
            'type' => 'string',
            'description' => 'Login username of the person who recorded expenses.',
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

        $recordedById = null;
        $recordedByLabel = null;
        $name = trim((string) ($arguments['user_name'] ?? $arguments['username'] ?? ''));
        if ($name !== '') {
            $resolved = $this->resolveUserByName((int) $organization->id, $name, false);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, [
                    'screens' => [['label' => 'Expenses', 'path' => '/expenses']],
                ]);
            }
            /** @var User $recorder */
            $recorder = $resolved['user'];
            $recordedById = (int) $recorder->id;
            $recordedByLabel = trim((string) ($recorder->full_name ?: $recorder->username));
        }

        $slice = $this->insightData->expenseSummarySlice(
            $organization,
            $user,
            $from,
            $to,
            $branchId,
            $recordedById,
        );

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'filtered_user' => $recordedByLabel,
            'screens' => [
                ['label' => 'Expenses', 'path' => '/expenses'],
                ['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile'],
                ['label' => 'Profit & loss', 'path' => '/reports/profit-loss'],
            ],
            'tip' => ($recordedByLabel
                ? "These figures are {$recordedByLabel}'s expenses (accounting recorded_by + mobile route user_id). "
                    .'Never say Centrix cannot attribute expenses to a salesperson. '
                    .'Quote filtered_user, combined_user_total, expense_lines, and mobile_route_expenses.'
                : 'Organization-wide expense totals by category. '
                    .'If the user named a person, call again with user_name — do NOT claim expenses are not per-user.')
                .' Use display names only — never numeric user ids.',
        ]);
    }
}
