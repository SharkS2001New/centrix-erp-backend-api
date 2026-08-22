<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiInsightDataBuilder;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Read-only sales totals for a date or date range — org-scoped via existing metrics.
 */
class GetSalesSummaryTool implements AiToolInterface
{
    public function __construct(
        protected AiInsightDataBuilder $insightData,
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'get_sales_summary';
    }

    public function description(): string
    {
        return 'Get Centrix sales totals (gross sales and transaction count) for a date or date range '
            .'for the authenticated user\'s organization only. Use for questions like "sales today" or "sales yesterday".';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Single calendar day as YYYY-MM-DD. Prefer this for "today" / "yesterday".',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Range start YYYY-MM-DD (inclusive). Use with to_date.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Range end YYYY-MM-DD (inclusive). Use with from_date.',
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

        // Hard tenant lock — never accept another org id from the model.
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

        // Strip any hallucinated org/tenant args — never trust the model for tenancy.
        unset($arguments['organization_id'], $arguments['company_id'], $arguments['tenant_id']);

        [$from, $to] = $this->resolveDates($arguments);
        $summary = $this->insightData->salesSummaryForPeriod($organization, $user, $from, $to);

        return array_merge($summary, [
            'organization_id' => $orgId,
            'currency' => 'KES',
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

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: string}
     */
    protected function resolveDates(array $arguments): array
    {
        $date = trim((string) ($arguments['date'] ?? ''));
        $from = trim((string) ($arguments['from_date'] ?? ''));
        $to = trim((string) ($arguments['to_date'] ?? ''));

        if ($date !== '') {
            $day = Carbon::parse($date)->toDateString();

            return [$day, $day];
        }

        if ($from !== '' && $to !== '') {
            $fromDay = Carbon::parse($from)->toDateString();
            $toDay = Carbon::parse($to)->toDateString();
            if ($fromDay > $toDay) {
                [$fromDay, $toDay] = [$toDay, $fromDay];
            }
            // Cap range to 90 days to limit exposure.
            if (Carbon::parse($fromDay)->diffInDays(Carbon::parse($toDay)) > 90) {
                $fromDay = Carbon::parse($toDay)->subDays(90)->toDateString();
            }

            return [$fromDay, $toDay];
        }

        $today = now()->toDateString();

        return [$today, $today];
    }
}
