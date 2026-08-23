<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetDebtorsSummaryTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_debtors_summary';
    }

    public function description(): string
    {
        return 'Get Centrix credit/debtors overview: total balance due, top debtors, and who to call. '
            .'Use for unpaid invoices, AR, collections, or "who owes us".';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days of history for volume context (1–90). Default 30.',
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
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate)
            || $this->permissions->hasPermission($user, 'customers.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view debtors data.',
                'screens' => [['label' => 'Customer statement', 'path' => '/reports/customer-statement']],
            ];
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 30);
        $slice = $this->insightData->debtorsBriefSlice($organization, $user, $lookback);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Customer statement', 'path' => '/reports/customer-statement'],
                ['label' => 'Sales orders', 'path' => '/sales/orders'],
            ],
        ]);
    }
}
