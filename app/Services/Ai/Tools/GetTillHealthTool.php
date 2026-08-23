<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetTillHealthTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_till_health';
    }

    public function description(): string
    {
        return 'Get Centrix cash & till health: recent till sessions, variance outliers, and payment mix '
            .'(cash / M-Pesa / bank). Use for till variance, blind close, or cashier cash questions.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days of till history (1–90). Default 14.',
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
            || $this->permissions->hasPermission($user, 'sales.pos.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view till data.',
            ];
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 14);
        $slice = $this->insightData->cashTillHealthSlice($organization, $user, $lookback);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'POS', 'path' => '/sales/pos'],
                ['label' => 'Reports', 'path' => '/reports'],
            ],
        ]);
    }
}
