<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

/**
 * Read-only sales totals grouped by cashier for a date or range.
 */
class GetSalesByCashierTool implements AiToolInterface
{
    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_sales_by_cashier';
    }

    public function description(): string
    {
        return 'Get Centrix sales totals grouped by cashier for a date or date range '
            .'(placed date — same as Sales by User report). Use relative_date=yesterday or today '
            .'for calendar questions. Pass cashier_name or username when asking about one person. '
            .'In replies, use username and full name — never numeric user ids.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'relative_date' => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'last_7_days'],
                    'description' => 'Prefer this for natural language like "yesterday" or "today" — resolved server-side in org timezone.',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Single calendar day as YYYY-MM-DD when relative_date is not used.',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Range start YYYY-MM-DD (inclusive). Use with to_date.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Range end YYYY-MM-DD (inclusive). Use with from_date.',
                ],
                'cashier_name' => [
                    'type' => 'string',
                    'description' => 'Filter to one cashier by full name or username (server-side match).',
                ],
                'username' => [
                    'type' => 'string',
                    'description' => 'Login username of the cashier (preferred over numeric ids).',
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
        $canViewSales = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate);
        if (! $canViewSales) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view sales data.',
            ];
        }

        unset($arguments['organization_id'], $arguments['company_id'], $arguments['tenant_id']);

        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $cashierId = isset($arguments['cashier_id']) ? (int) $arguments['cashier_id'] : null;
        $cashierName = trim((string) ($arguments['cashier_name'] ?? $arguments['username'] ?? ''));

        $summary = $this->insightData->salesByCashierForPeriod(
            $organization,
            $user,
            $from,
            $to,
            $cashierId > 0 ? $cashierId : null,
            $cashierName !== '' ? $cashierName : null,
        );

        unset($summary['organization_id']);

        return $summary;
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
