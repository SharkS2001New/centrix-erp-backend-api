<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

/**
 * Read-only stock overview (low stock + recent movers) for AI chat.
 */
class GetStockSummaryTool implements AiToolInterface
{
    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_stock_summary';
    }

    public function description(): string
    {
        return 'Get a Centrix inventory overview: low-stock items and recent fast movers. '
            .'Each product row includes qty_label (Centrix stock UoM text, e.g. "2 Bag, 40 kg") — quote that in answers. '
            .'Use for questions about current stock levels, reorder alerts, or what is selling. '
            .'For full stock lists, also point the user to /inventory/stock.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lookback_days' => [
                    'type' => 'integer',
                    'description' => 'Days of sales history for fast movers (1–90). Default 14.',
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

        $orgId = (int) $organization->id;
        if ((int) $user->organization_id !== $orgId && ! $user->is_super_admin) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'inventory.stock.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view inventory data.',
                'screens' => [
                    ['label' => 'Current stock', 'path' => '/inventory/stock'],
                ],
            ];
        }

        $lookback = (int) ($arguments['lookback_days'] ?? 14);
        $slice = $this->insightData->stockPulseSlice($organization, $user, $lookback);

        return array_merge($slice, [
            'organization_id' => $orgId,
            'screens' => [
                ['label' => 'Current stock', 'path' => '/inventory/stock'],
                ['label' => 'Stock receipts (GRN)', 'path' => '/inventory/receipts'],
                ['label' => 'Low stock report', 'path' => '/reports/low-stock'],
            ],
        ]);
    }

    protected function resolveOrganizationForUser(User $user): ?Organization
    {
        $request = request();
        $actingId = $request->attributes->get('acting_organization_id');
        if ($actingId && ($request->user()?->id === $user->id || $user->is_super_admin)) {
            $acting = Organization::query()->find((int) $actingId);
            if ($acting) {
                return $acting;
            }
        }

        return Organization::query()->find((int) $user->organization_id);
    }
}
