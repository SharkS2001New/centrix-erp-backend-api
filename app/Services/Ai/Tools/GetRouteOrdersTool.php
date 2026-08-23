<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

class GetRouteOrdersTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_route_orders';
    }

    public function description(): string
    {
        return 'Get Centrix mobile/route order debrief: booked vs delivered, unpaid on route, top SKUs, '
            .'and stalled customers. Use for route sales, mobile orders, or delivery performance.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days of route history (1–30). Default 1 (today/yesterday window).',
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
            || $this->permissions->hasPermission($user, 'distribution.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view route order data.',
                'screens' => [['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile']],
            ];
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 1);
        $slice = $this->insightData->routeMobileDebriefSlice($organization, $user, $lookback);

        return array_merge($slice, [
            'organization_id' => (int) $organization->id,
            'currency' => 'KES',
            'screens' => [
                ['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile'],
                ['label' => 'Sales orders', 'path' => '/sales/orders'],
            ],
        ]);
    }
}
