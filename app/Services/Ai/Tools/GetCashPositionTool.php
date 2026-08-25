<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetCashPositionTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_cash_position';
    }

    public function description(): string
    {
        return 'Treasury-style cash position: open till float, recent payment mix (cash/M-Pesa/bank from sales), '
            .'GL cash & bank account balances, accounts receivable snapshot, and estimated supplier payables. '
            .'Use for "how much cash do I have", working capital context, and can-I-pay-supplier questions. '
            .'Do not conflate till cash with accounting cash flow statement (/reports/cash-flow).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days for recent payment mix (1–90). Default 14.',
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
            || $this->permissions->hasPermission($user, 'accounting.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.pos.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view cash position data.',
            ];
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 14);
        $slice = $this->insightData->cashPositionSlice($organization, $user, $lookback);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Cash flow', 'path' => '/reports/cash-flow'],
                ['label' => 'Till sessions', 'path' => '/reports/till-sessions'],
            ],
        ]);
    }
}
