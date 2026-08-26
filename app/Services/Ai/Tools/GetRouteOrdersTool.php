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

class GetRouteOrdersTool implements AiToolInterface
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
        return 'get_route_orders';
    }

    public function description(): string
    {
        return 'Get Centrix mobile/route order debrief: booked vs delivered, unpaid on route, top SKUs, '
            .'and stalled customers. Use for route sales, mobile orders, or delivery performance. '
            .'Pass relative_date=yesterday/today (or from_date/to_date). Optional user_name/username filters '
            .'to one mobile rep (cashier). Prefer this for "mobile sales yesterday".';
    }

    public function parametersSchema(): array
    {
        $schema = $this->aiPeriodParametersSchema(includeLookback: true);
        $schema['properties']['user_name'] = [
            'type' => 'string',
            'description' => 'Mobile rep / cashier full name or username to filter orders.',
        ];
        $schema['properties']['username'] = [
            'type' => 'string',
            'description' => 'Login username of the mobile rep (preferred when known).',
        ];
        $schema['properties']['lookback_days']['description'] = 'Days of route history when relative_date is omitted (1–30). Default 1.';

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
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate)
            || $this->permissions->hasPermission($user, 'distribution.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view route order data.',
                'screens' => [['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile']],
            ];
        }

        $orgId = (int) $organization->id;
        $hasExplicitPeriod = trim((string) ($arguments['relative_date'] ?? '')) !== ''
            || trim((string) ($arguments['from_date'] ?? '')) !== ''
            || trim((string) ($arguments['to_date'] ?? '')) !== ''
            || trim((string) ($arguments['year_month'] ?? '')) !== ''
            || trim((string) ($arguments['month'] ?? '')) !== '';

        if ($hasExplicitPeriod) {
            [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        } else {
            $lookback = max(1, min(30, (int) ($arguments['lookback_days'] ?? 1)));
            $calendar = AiSalesDateResolver::calendarAnchor($organization);
            $to = $calendar['today'];
            $from = \Carbon\Carbon::parse($to, $calendar['timezone'])->subDays($lookback - 1)->toDateString();
        }

        $cashierId = null;
        $cashierLabel = null;
        $name = trim((string) ($arguments['user_name'] ?? $arguments['username'] ?? $arguments['cashier_name'] ?? ''));
        if ($name !== '') {
            $resolved = $this->resolveUserByName($orgId, $name, false);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, [
                    'screens' => [['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile']],
                ]);
            }
            /** @var User $rep */
            $rep = $resolved['user'];
            $cashierId = (int) $rep->id;
            $cashierLabel = trim((string) ($rep->full_name ?: $rep->username));
        }

        $slice = $this->insightData->routeMobileDebriefSlice(
            $organization,
            $user,
            max(1, (int) (\Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to)) + 1)),
            $from,
            $to,
            $cashierId,
        );

        return array_merge($slice, [
            'organization_id' => $orgId,
            'currency' => 'KES',
            'filtered_user' => $cashierLabel,
            'screens' => [
                ['label' => 'Mobile orders', 'path' => '/sales/orders/queues/mobile'],
                ['label' => 'Sales orders', 'path' => '/sales/orders'],
            ],
            'tip' => 'Summarize mobile/route orders for the period'
                .($cashierLabel ? " for {$cashierLabel}" : '')
                .'. Use display names only — never numeric user ids.',
        ]);
    }
}
