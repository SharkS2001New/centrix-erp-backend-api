<?php

namespace App\Services\Ai;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Reports\ReportBuilderService;
use Illuminate\Support\Facades\DB;

class AiSystemContextBuilder
{
    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected ReportBuilderService $reportBuilder,
        protected AiEntitySchemaCatalog $entitySchemas,
        protected AiKnowledgeService $knowledge,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, string $message, ?array $workspaceScope = null): array
    {
        $org = Organization::find($user->organization_id);
        $gate = $this->erp->gateForUser($user);
        $caps = $gate->toArray();
        $userAccess = $this->userAccessSummary($user, $gate);
        $scope = $workspaceScope ?? [
            'id' => 'backoffice',
            'label' => 'Backoffice',
            'nav_section_ids' => [],
            'action_types' => [],
            'module_catalog_keys' => [],
            'workflow_keys' => [],
        ];

        $navigation = $this->visibleNavigation($gate, $user);
        $actions = $this->availableActions($gate, $user);
        $moduleCatalog = config('ai_knowledge.modules', []);
        $workflows = config('ai_knowledge.workflows', []);

        if ($workspaceScope !== null) {
            $scopeService = app(AiWorkspaceScope::class);
            $navigation = $scopeService->filterNavigation($navigation, $workspaceScope);
            $actions = $scopeService->filterActions($actions, $workspaceScope);
            $moduleCatalog = $scopeService->filterModuleCatalog($moduleCatalog, $workspaceScope);
            $workflows = $scopeService->filterWorkflows($workflows, $workspaceScope);
        }

        $context = [
            'active_workspace' => [
                'id' => $scope['id'],
                'label' => $scope['label'],
                'description' => $scope['description'] ?? '',
            ],
            'organization' => [
                'id' => $org?->id,
                'name' => $org?->org_name,
                'deployment_profile' => $caps['deployment_profile'] ?? null,
                'profile_label' => $caps['profile_label'] ?? null,
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->full_name ?? $user->username,
                'branch_id' => $user->branch_id,
                'is_admin' => (bool) $user->is_admin,
            ],
            'user_access' => $userAccess,
            'enabled_modules' => array_keys(array_filter($caps['modules'] ?? [])),
            'navigation' => $navigation,
            'available_actions' => $actions,
            'report_builder_modules' => array_column($this->reportBuilder->schema()['modules'] ?? [], 'name'),
            'module_catalog' => $moduleCatalog,
            'workflows' => $workflows,
            'entity_schemas' => $this->entitySchemas->summaryForContext($user),
            'organization_knowledge' => $this->knowledge->confirmedForOrganization((int) $user->organization_id),
        ];

        $lower = strtolower($message);
        if ($this->needsLookup($lower, 'product', 'catalog', 'sku')) {
            $context['entity_detail']['product'] = $this->entitySchemas->forEntityWithOptions($user, 'product');
        }
        if ($this->needsLookup($lower, 'employee', 'hire', 'staff', 'hr')) {
            $context['entity_detail']['employee'] = $this->entitySchemas->forEntityWithOptions($user, 'employee');
        }
        if ($this->needsLookup($lower, 'order', 'sale', 'checkout')) {
            $context['entity_detail']['sales_order'] = $this->entitySchemas->forEntityWithOptions($user, 'sales_order');
        }
        if ($this->needsLookup($lower, 'product', 'stock', 'reorder', 'catalog', 'category', 'sku')) {
            $context['product_summary'] = $this->productSummary($user);
            $context['product_search'] = $this->searchProducts($user, $message);
        }
        if ($this->shouldIncludeSalesSummary($user, $lower, $userAccess)) {
            $context['sales_summary'] = $this->salesSummary($user);
        }
        if ($this->needsLookup($lower, 'receivable', 'debt', 'invoice', 'balance', 'debtor', 'owed', 'outstanding', 'ar ')) {
            $context['receivables_summary'] = $this->receivablesSummary($user);
        }
        if ($this->needsLookup($lower, 'payment', 'pay', 'collect', 'partial', 'debtor', 'receivable')) {
            $context['entity_detail']['customer_payment'] = $this->entitySchemas->forEntityWithOptions($user, 'customer_payment');
        }
        if ($this->needsLookup($lower, 'report', 'builder', 'template')) {
            $context['report_builder_schema'] = $this->reportBuilder->schema();
        }
        if ($this->needsLookup($lower, 'employee', 'hire', 'staff', 'hr')) {
            $context['hr_reference'] = $this->hrReference($user);
        }
        if ($this->needsLookup($lower, 'customer', 'order', 'held')) {
            $context['customer_search'] = $this->searchCustomers($user, $message);
        }

        return $context;
    }

    public function gateForUser(User $user): CapabilityGate
    {
        return $this->erp->gateForUser($user);
    }

    protected function needsLookup(string $lower, string ...$keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    protected function userAccessSummary(User $user, CapabilityGate $gate): array
    {
        $moduleChecks = [
            'sales' => [
                'org_enabled' => $gate->enabled('sales.backend') || $gate->enabled('sales.pos'),
                'permission' => 'sales.view',
            ],
            'reports' => [
                'org_enabled' => $gate->enabled('reports'),
                'permission' => 'reports.view',
            ],
            'payments' => [
                'org_enabled' => $gate->enabled('payments'),
                'permission' => 'payments.view',
            ],
            'inventory' => [
                'org_enabled' => $gate->enabled('inventory'),
                'permission' => 'inventory.view',
            ],
            'accounting' => [
                'org_enabled' => $gate->enabled('accounting'),
                'permission' => 'accounting.view',
            ],
            'hr' => [
                'org_enabled' => $gate->enabled('hr_payroll'),
                'permission' => 'hr.view',
            ],
            'catalogue' => [
                'org_enabled' => true,
                'permission' => 'catalogue.view',
            ],
        ];

        $modules = [];
        foreach ($moduleChecks as $key => $check) {
            $hasPermission = $this->permissions->hasPermission($user, $check['permission']);
            $modules[$key] = [
                'org_module_enabled' => $check['org_enabled'],
                'user_has_permission' => $hasPermission,
                'can_access' => $check['org_enabled'] && $hasPermission,
            ];
        }

        return [
            'is_admin' => (bool) $user->is_admin,
            'has_full_permissions' => (bool) $user->is_admin,
            'modules' => $modules,
            'guidance' => [
                'Answer read-only questions using *_summary data already in this context — do not refuse when that data is present.',
                'Only decline WRITE actions (create/update/delete) that are not listed in available_actions.',
                'org_module_enabled=false means the organization disabled the module, not that this user lacks role permissions.',
                'Never tell an admin (is_admin=true) to contact an administrator.',
            ],
        ];
    }

    /** @param  array<string, mixed>  $userAccess */
    protected function shouldIncludeSalesSummary(User $user, string $lower, array $userAccess): bool
    {
        $canViewSales = ($userAccess['modules']['sales']['can_access'] ?? false)
            || ($userAccess['modules']['reports']['can_access'] ?? false);

        if (! $canViewSales) {
            return false;
        }

        return $this->needsLookup(
            $lower,
            'sales',
            'revenue',
            'order',
            'channel',
            'sold',
            'selling',
            'report',
            'summary',
            'performance',
            'data',
            'dashboard',
        );
    }

    /** @return list<array<string, mixed>> */
    protected function visibleNavigation(CapabilityGate $gate, User $user): array
    {
        $out = [];
        $distEnabled = $gate->distributionOpsEnabled();

        foreach (config('ai_navigation.sections', []) as $section) {
            if (! empty($section['requires_distribution_ops']) && ! $distEnabled) {
                continue;
            }
            if (! empty($section['module']) && ! $gate->enabled($section['module'])) {
                continue;
            }

            $items = [];
            foreach ($section['items'] ?? [] as $item) {
                if (! empty($item['requires_distribution_ops']) && ! $distEnabled) {
                    continue;
                }
                if (! empty($item['requires_admin']) && ! $user->is_admin) {
                    continue;
                }
                if (! empty($item['module']) && ! $gate->enabled($item['module'])) {
                    continue;
                }
                if (! empty($item['permission']) && ! $this->permissions->hasPermission($user, $item['permission'])) {
                    continue;
                }
                $items[] = [
                    'label' => $item['label'],
                    'path' => $item['path'],
                    'section' => $section['label'],
                ];
            }

            if ($items !== []) {
                $out[] = [
                    'id' => $section['id'] ?? null,
                    'section' => $section['label'],
                    'items' => $items,
                ];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected function availableActions(CapabilityGate $gate, User $user): array
    {
        $actions = [];
        foreach (config('ai_navigation.actions', []) as $action) {
            if (! empty($action['module']) && ! $gate->enabled($action['module'])) {
                continue;
            }
            if (! empty($action['permission']) && ! $this->permissions->hasPermission($user, $action['permission'])) {
                continue;
            }
            $actions[] = [
                'type' => $action['type'],
                'label' => $action['label'],
                'description' => $action['description'],
            ];
        }

        return $actions;
    }

    /** @return array<string, mixed> */
    protected function hrReference(User $user): array
    {
        return [
            'departments' => Department::query()
                ->where('organization_id', $user->organization_id)
                ->orderBy('department_name')
                ->limit(20)
                ->get(['id', 'department_name'])
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->department_name])
                ->all(),
            'shifts' => WorkShift::query()
                ->where('organization_id', $user->organization_id)
                ->orderBy('shift_name')
                ->limit(20)
                ->get(['id', 'shift_name'])
                ->map(fn ($row) => ['id' => $row->id, 'name' => $row->shift_name])
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function searchCustomers(User $user, string $message): array
    {
        if (! preg_match('/["\']([^"\']+)["\']|customer\s+(\w+)/i', $message, $m)) {
            return [];
        }
        $term = trim($m[1] ?? $m[2] ?? '');
        if (strlen($term) < 2) {
            return [];
        }

        return DB::table('customers')
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_num', 'like', "%{$term}%");
            })
            ->orderBy('customer_name')
            ->limit(8)
            ->get(['customer_num', 'customer_name', 'phone', 'current_balance'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function searchProducts(User $user, string $message): array
    {
        if (! preg_match('/(\d+)\s*(?:x|×|bags?|units?|pcs?)?\s*(?:of\s+)?(.+)/i', $message, $m)) {
            return [];
        }
        $term = trim($m[2] ?? '');
        if (strlen($term) < 2) {
            return [];
        }

        return DB::table('products')
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($term) {
                $q->where('product_name', 'like', "%{$term}%")
                    ->orWhere('product_code', 'like', "%{$term}%");
            })
            ->orderBy('product_name')
            ->limit(8)
            ->get(['product_code', 'product_name', 'unit_price'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array<string, mixed> */
    protected function productSummary(User $user): array
    {
        $orgId = $user->organization_id;

        return [
            'total_products' => DB::table('products')->where('organization_id', $orgId)->whereNull('deleted_at')->count(),
            'low_stock_skus' => DB::table('current_stock as cs')
                ->join('products as p', 'p.product_code', '=', 'cs.product_code')
                ->where('p.organization_id', $orgId)
                ->whereNull('p.deleted_at')
                ->whereRaw('(cs.shop_quantity + cs.store_quantity) <= COALESCE(p.reorder_point, 0)')
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    protected function salesSummary(User $user): array
    {
        $from = now()->subDays(29)->toDateString();
        $to = now()->toDateString();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total_sales_kes' => (float) DB::table('sales')
                ->where('organization_id', $user->organization_id)
                ->where('status', 'completed')
                ->where('archived', 0)
                ->whereDate('completed_at', '>=', $from)
                ->whereDate('completed_at', '<=', $to)
                ->sum('order_total'),
        ];
    }

    /** @return array<string, mixed> */
    protected function receivablesSummary(User $user): array
    {
        $orgId = $user->organization_id;

        $topDebtors = DB::table('customers as c')
            ->join('customer_invoices as ci', function ($join) use ($orgId) {
                $join->on('ci.customer_num', '=', 'c.customer_num')
                    ->where('ci.organization_id', '=', $orgId)
                    ->whereNull('ci.deleted_at')
                    ->where('ci.balance_due', '>', 0);
            })
            ->where('c.organization_id', $orgId)
            ->whereNull('c.deleted_at')
            ->groupBy('c.customer_num', 'c.customer_name')
            ->selectRaw('c.customer_num, c.customer_name, SUM(ci.balance_due) as total_owed_kes')
            ->orderByDesc('total_owed_kes')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'customer_num' => (int) $row->customer_num,
                'customer_name' => (string) $row->customer_name,
                'total_owed_kes' => (float) $row->total_owed_kes,
            ])
            ->all();

        $openInvoices = DB::table('customer_invoices as ci')
            ->join('sales as s', 's.id', '=', 'ci.sale_id')
            ->join('customers as c', 'c.customer_num', '=', 'ci.customer_num')
            ->where('ci.organization_id', $orgId)
            ->whereNull('ci.deleted_at')
            ->where('ci.balance_due', '>', 0)
            ->orderByDesc('ci.balance_due')
            ->limit(20)
            ->get([
                's.id as sale_id',
                's.order_num',
                'c.customer_num',
                'c.customer_name',
                'ci.balance_due',
                's.order_total',
                's.amount_paid',
                's.payment_status',
            ])
            ->map(fn ($row) => [
                'sale_id' => (int) $row->sale_id,
                'order_num' => (string) $row->order_num,
                'customer_num' => (int) $row->customer_num,
                'customer_name' => (string) $row->customer_name,
                'balance_due_kes' => (float) $row->balance_due,
                'order_total_kes' => (float) $row->order_total,
                'amount_paid_kes' => (float) $row->amount_paid,
                'payment_status' => (string) $row->payment_status,
            ])
            ->all();

        return [
            'total_outstanding_kes' => (float) DB::table('customer_invoices')
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->where('balance_due', '>', 0)
                ->sum('balance_due'),
            'top_debtors' => $topDebtors,
            'open_invoices' => $openInvoices,
        ];
    }
}
