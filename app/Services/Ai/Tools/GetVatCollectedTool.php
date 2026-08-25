<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Support\CentrixSalesScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * VAT collected on sales (output VAT) for a period — for "how much VAT this month / August".
 */
class GetVatCollectedTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected UserAccessService $access,
    ) {}

    public function name(): string
    {
        return 'get_vat_collected';
    }

    public function description(): string
    {
        return 'Get total VAT collected on Centrix sales (output VAT / VAT on taxable sales) for a date range. '
            .'Use for "how much VAT this month", "VAT sales August", "VAT I need to pay for August" (sales VAT). '
            .'Supports relative_date=this_month/last_month, month+year, year_month=YYYY-MM, or from_date/to_date. '
            .'Returns vat_collected_total, taxable/gross sales, order count, and optional daily rows. '
            .'Also point users to /reports/vat-collected. Do not use find_screen alone for VAT amounts.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'relative_date' => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month'],
                ],
                'month' => [
                    'type' => 'string',
                    'description' => 'Calendar month name or number (e.g. august, Aug, 8). Pair with year.',
                ],
                'year' => [
                    'type' => 'integer',
                    'description' => 'Calendar year for month (e.g. 2026).',
                ],
                'year_month' => [
                    'type' => 'string',
                    'description' => 'YYYY-MM period (e.g. 2026-08).',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Period start YYYY-MM-DD.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Period end YYYY-MM-DD.',
                ],
                'include_daily' => [
                    'type' => 'boolean',
                    'description' => 'If true, include up to 31 daily rows. Default false for a period total.',
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
            || $this->permissions->hasPermission($user, 'reports.vat_collected.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view VAT collected figures.',
                'screens' => $this->screens(),
            ];
        }

        unset($arguments['organization_id'], $arguments['company_id'], $arguments['tenant_id']);
        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $orgId = (int) $organization->id;
        $branchId = $this->access->branchId($user);
        $includeDaily = (bool) ($arguments['include_daily'] ?? false);

        $totals = $this->totals($orgId, $branchId, $from, $to);
        $daily = $includeDaily ? $this->dailyRows($orgId, $branchId, $from, $to) : [];

        return [
            'currency' => 'KES',
            'period' => [
                'from_date' => $from,
                'to_date' => $to,
                'label' => $from === $to ? $from : "{$from} → {$to}",
            ],
            'summary' => [
                'vat_collected_total' => $totals['vat_collected'],
                'taxable_sales_gross' => $totals['gross_sales'],
                'orders' => $totals['orders'],
            ],
            'daily' => $daily,
            'note' => 'This is VAT collected on Centrix sales (output VAT on booked→completed orders). '
                .'Net VAT payable to KRA may also subtract input VAT on purchases if tracked outside this report.',
            'screens' => $this->screens(),
            'tip' => 'Answer with vat_collected_total in KES for the period. Link /reports/vat-collected for the full day/branch breakdown. '
                .'Do not invent VAT or only suggest LPO/purchasing screens.',
        ];
    }

    /**
     * @return array{vat_collected: float, gross_sales: float, orders: int}
     */
    protected function totals(int $orgId, ?int $branchId, string $from, string $to): array
    {
        if ($this->viewExists('v_vat_collected')) {
            $query = DB::table('v_vat_collected')
                ->where('organization_id', $orgId)
                ->whereBetween('sale_date', [$from, $to]);
            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
            $row = (clone $query)->selectRaw(
                'COALESCE(SUM(vat_collected), 0) as vat_collected, '
                .'COALESCE(SUM(gross_sales), 0) as gross_sales, '
                .'COALESCE(SUM(orders), 0) as orders'
            )->first();

            return [
                'vat_collected' => round((float) ($row->vat_collected ?? 0), 2),
                'gross_sales' => round((float) ($row->gross_sales ?? 0), 2),
                'orders' => (int) ($row->orders ?? 0),
            ];
        }

        if (! Schema::hasTable('sales')) {
            return ['vat_collected' => 0.0, 'gross_sales' => 0.0, 'orders' => 0];
        }

        $saleDateSql = CentrixSalesScope::reportSaleDateSql('s');
        $query = DB::table('sales as s')
            ->where('s.organization_id', $orgId)
            ->whereRaw(CentrixSalesScope::reportPipelineStatusSql('s.status'))
            ->where('s.archived', 0)
            ->whereRaw("{$saleDateSql} BETWEEN ? AND ?", [$from, $to])
            ->whereRaw(CentrixSalesScope::legacyExcludeSql('s'));
        if ($branchId !== null) {
            $query->where('s.branch_id', $branchId);
        }
        $row = (clone $query)->selectRaw(
            'COALESCE(SUM(s.total_vat), 0) as vat_collected, '
            .'COALESCE(SUM(s.order_total), 0) as gross_sales, '
            .'COUNT(*) as orders'
        )->first();

        return [
            'vat_collected' => round((float) ($row->vat_collected ?? 0), 2),
            'gross_sales' => round((float) ($row->gross_sales ?? 0), 2),
            'orders' => (int) ($row->orders ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function dailyRows(int $orgId, ?int $branchId, string $from, string $to): array
    {
        if (! $this->viewExists('v_vat_collected')) {
            return [];
        }

        $query = DB::table('v_vat_collected')
            ->where('organization_id', $orgId)
            ->whereBetween('sale_date', [$from, $to]);
        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query
            ->selectRaw(
                'sale_date, '
                .'COALESCE(SUM(vat_collected), 0) as vat_collected, '
                .'COALESCE(SUM(gross_sales), 0) as gross_sales, '
                .'COALESCE(SUM(orders), 0) as orders'
            )
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->limit(31)
            ->get()
            ->map(fn ($row) => [
                'sale_date' => (string) $row->sale_date,
                'vat_collected' => round((float) $row->vat_collected, 2),
                'gross_sales' => round((float) $row->gross_sales, 2),
                'orders' => (int) $row->orders,
            ])
            ->all();
    }

    protected function viewExists(string $view): bool
    {
        try {
            DB::select('select 1 from '.$view.' limit 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(): array
    {
        return [
            ['label' => 'VAT collected report', 'path' => '/reports/vat-collected'],
            ['label' => 'Reports hub', 'path' => '/reports'],
        ];
    }
}
