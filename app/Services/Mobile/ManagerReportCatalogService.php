<?php

namespace App\Services\Mobile;

use App\Http\Controllers\Api\V1\Operations\ReportController;
use App\Models\Branch;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Legacy\OrganizationLegacyArchiveService;
use Illuminate\Http\Request;

class ManagerReportCatalogService
{
    /** @var list<string> */
    private const POS_REPORT_KEYS = [
        'eod-cashier',
        'eod-report',
        'till-sessions',
        'discount-summary',
        'payment-collection',
        'vat-collected',
    ];

    /** @var list<string> */
    private const DISTRIBUTION_REPORT_KEYS = [
        'mobile-route-sales',
        'dispatch-trips',
        'trip-cash-settlement',
        'pod-compliance',
        'driver-deliveries',
    ];

    /** @var list<string> */
    private const FINANCE_REPORT_KEYS = [
        'profit-loss', 'profit-loss-gl', 'trial-balance', 'balance-sheet',
        'cash-flow', 'general-ledger', 'accounts-payable', 'expenses',
        'journal-register', 'subledger-reconciliation', 'accounts-receivable',
        'invoice-payments', 'credit-outstanding',
    ];

    /** @var list<string> */
    private const HR_REPORT_KEYS = [
        'leave-balance', 'payroll-summary', 'statutory-deductions', 'bank-transfer',
        'staff-turnover', 'headcount', 'contract-expiry', 'hr-dashboard-kpi',
    ];

    /** @var list<string> */
    private const MOBILE_EXCLUDED_REPORT_KEYS = [
        'stock-reservations',
    ];

    /** @var list<string> */
    private const INVENTORY_REPORT_KEYS = [
        'items-currently-in-stock', 'low-stock', 'stock-movement', 'stock-chain',
        'stock-valuation', 'stock-transfers',
        'branch-stock-transfers', 'returns', 'price-list', 'stock-on-hand',
        'damages',
    ];

    /** @var list<string> */
    private const PURCHASES_REPORT_KEYS = [
        'open-lpo', 'purchases-by-supplier', 'stock-receipts', 'supplier-returns',
    ];

    /** @var list<string> */
    private const SALES_REPORT_KEYS = [
        'sales-by-product', 'sales-by-supplier', 'sales-by-user', 'sales-by-customer',
        'sales-by-channel', 'daily-sales', 'sales-pipeline', 'category-sales',
    ];

    /** @var list<string> */
    private const MULTI_BRANCH_REPORT_KEYS = [
        'branch-stock-transfers',
    ];

    /** @var list<string> */
    private const CUSTOMER_REPORT_KEYS = [
        'customer-statement', 'ar-aging', 'credit-outstanding', 'top-debtors',
        'accounts-receivable', 'invoice-payments',
    ];

    /** @var list<string> */
    public const FEATURED_KEYS = [
        'daily-sales',
        'items-currently-in-stock',
        'profit-loss',
        'top-debtors',
        'stock-movement',
        'vat-collected',
        'till-sessions',
        'expenses',
    ];

    /** @var array<string, string> */
    private const PERMISSION_BY_KEY = [
        'daily-sales' => 'reports.daily_sales.view',
        'items-currently-in-stock' => 'reports.stock_on_hand.view',
        'stock-on-hand' => 'reports.stock_on_hand.view',
        'stock-movement' => 'reports.stock_movement.view',
        'profit-loss' => 'reports.profit_loss.view',
        'profit-loss-gl' => 'reports.profit_loss.view',
        'top-debtors' => 'reports.top_debtors.view',
        'vat-collected' => 'reports.vat_collected.view',
        'till-sessions' => 'reports.till_sessions.view',
        'expenses' => 'reports.expenses.view',
        'customer-statement' => 'reports.customer_statement.view',
        'journal-register' => 'reports.journal_register.view',
        'ar-aging' => 'reports.ar_aging.view',
        'dispatch-trips' => 'reports.dispatch_trips.view',
        'driver-deliveries' => 'reports.driver_deliveries.view',
        'payroll-summary' => 'reports.payroll_summary.view',
        'legacy-archive' => 'reports.legacy_archive.view',
    ];

    /** @var list<array{id: string, title: string, description: string, keys: list<string>}> */
    private const CATEGORY_DEFS = [
        [
            'id' => 'sales',
            'title' => 'Sales Reports',
            'description' => 'Track revenue, transactions, and sales performance',
            'keys' => [
                'sales-by-product', 'sales-by-supplier', 'sales-by-user', 'sales-by-customer',
                'sales-by-channel', 'daily-sales', 'sales-pipeline', 'category-sales',
            ],
        ],
        [
            'id' => 'distribution',
            'title' => 'Distribution Reports',
            'description' => 'Route sales, trips, and delivery performance',
            'keys' => [
                'mobile-route-sales', 'dispatch-trips', 'trip-cash-settlement',
                'pod-compliance', 'driver-deliveries',
            ],
        ],
        [
            'id' => 'customers',
            'title' => 'Customers & Receivables',
            'description' => 'Customer balances, aging, and collections',
            'keys' => [
                'customer-statement', 'ar-aging', 'credit-outstanding', 'top-debtors',
                'accounts-receivable', 'invoice-payments',
            ],
        ],
        [
            'id' => 'inventory',
            'title' => 'Inventory Reports',
            'description' => 'Stock levels, movement, and valuation',
            'keys' => [
                'items-currently-in-stock', 'low-stock', 'stock-movement', 'stock-chain',
                'stock-valuation', 'stock-transfers',
                'branch-stock-transfers', 'returns', 'price-list',
            ],
        ],
        [
            'id' => 'purchases',
            'title' => 'Purchases Reports',
            'description' => 'Supplier purchases, LPOs, and returns',
            'keys' => [
                'open-lpo', 'purchases-by-supplier', 'stock-receipts',
                'supplier-returns', 'damages',
            ],
        ],
        [
            'id' => 'pos',
            'title' => 'POS Reports',
            'description' => 'Cashier sessions, till floats, and POS metrics',
            'keys' => [
                'eod-cashier', 'eod-report', 'till-sessions', 'discount-summary',
                'payment-collection', 'vat-collected',
            ],
        ],
        [
            'id' => 'finance',
            'title' => 'Finance & Accounting',
            'description' => 'P&L, balance sheet, ledger, and expenses',
            'keys' => [
                'profit-loss', 'profit-loss-gl', 'trial-balance', 'balance-sheet',
                'cash-flow', 'general-ledger', 'accounts-payable', 'expenses',
                'journal-register', 'subledger-reconciliation',
            ],
        ],
        [
            'id' => 'compliance',
            'title' => 'Compliance Reports',
            'description' => 'Tax receipts and audit trail',
            'keys' => ['kra-receipts', 'audit-trail'],
        ],
        [
            'id' => 'hr',
            'title' => 'Payroll & workforce',
            'description' => 'Leave, payroll, headcount, and workforce analytics',
            'keys' => [
                'leave-balance', 'payroll-summary', 'statutory-deductions', 'bank-transfer',
                'staff-turnover', 'headcount', 'contract-expiry', 'hr-dashboard-kpi',
            ],
        ],
    ];

    public function __construct(
        protected UserPermissionService $permissions,
    ) {}

    /** @return array<string, mixed> */
    public function catalogForUser(User $user, CapabilityGate $gate): array
    {
        $request = Request::create('/api/v1/reports/', 'GET');
        $request->setUserResolver(fn () => $user);
        $rawCatalog = app(ReportController::class)->catalog($request)->getData(true);

        $byKey = [];
        foreach ($rawCatalog as $group => $items) {
            if ($group === 'filters' || ! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (! is_array($item) || empty($item['key'])) {
                    continue;
                }
                $byKey[(string) $item['key']] = $item;
            }
        }

        $multiBranch = $this->organizationHasMultipleBranches($user);
        $legacyEnabled = $this->legacyArchiveEnabled($user, $gate);

        $visibleKeys = [];
        foreach ($byKey as $key => $item) {
            if (in_array($key, self::MOBILE_EXCLUDED_REPORT_KEYS, true)) {
                continue;
            }
            if (! $this->reportVisibleForOrg($key, $gate, $multiBranch, $legacyEnabled)) {
                continue;
            }
            if (! $this->userCanViewReport($user, $key, $gate)) {
                continue;
            }
            $visibleKeys[$key] = $this->formatReportItem($key, $item);
        }

        $categories = [];
        foreach (self::CATEGORY_DEFS as $def) {
            $reports = [];
            foreach ($def['keys'] as $key) {
                if (isset($visibleKeys[$key])) {
                    $reports[] = $visibleKeys[$key];
                }
            }
            if ($reports === []) {
                continue;
            }
            $categories[] = [
                'id' => $def['id'],
                'title' => $def['title'],
                'description' => $def['description'],
                'reports' => $reports,
            ];
        }

        $featured = [];
        foreach (self::FEATURED_KEYS as $key) {
            if (isset($visibleKeys[$key])) {
                $featured[] = $visibleKeys[$key];
            }
        }

        $categorizedKeys = [];
        foreach ($categories as $category) {
            foreach ($category['reports'] as $report) {
                $categorizedKeys[(string) $report['key']] = true;
            }
        }

        $otherReports = [];
        foreach ($visibleKeys as $key => $item) {
            if (! isset($categorizedKeys[$key])) {
                $otherReports[] = $item;
            }
        }

        if ($otherReports !== []) {
            $categories[] = [
                'id' => 'other',
                'title' => 'More reports',
                'description' => 'Additional reports available for your organization',
                'reports' => $otherReports,
            ];
        }

        return [
            'categories' => $categories,
            'featured' => $featured,
            'report_count' => count($visibleKeys),
            'organization_id' => (int) $user->organization_id,
        ];
    }

    protected function userCanViewReport(User $user, string $key, CapabilityGate $gate): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($this->permissions->hasPermission($user, 'mobile_manager.app.access', $gate)) {
            return true;
        }

        $permission = self::PERMISSION_BY_KEY[$key] ?? 'reports.hub.view';

        return $this->permissions->hasPermission($user, $permission, $gate);
    }

    protected function reportVisibleForOrg(
        string $key,
        CapabilityGate $gate,
        bool $multiBranch,
        bool $legacyEnabled,
    ): bool {
        if ($key === 'legacy-archive' && ! $legacyEnabled) {
            return false;
        }

        if (in_array($key, self::MULTI_BRANCH_REPORT_KEYS, true) && ! $multiBranch) {
            return false;
        }

        if (in_array($key, self::POS_REPORT_KEYS, true) && ! $gate->enabled('sales.pos')) {
            return false;
        }

        if (in_array($key, self::DISTRIBUTION_REPORT_KEYS, true) && ! $gate->distributionOpsEnabled()) {
            return false;
        }

        if ($key === 'mobile-route-sales' && ! $gate->mobileSalesEnabled()) {
            return false;
        }

        if (in_array($key, self::FINANCE_REPORT_KEYS, true) && ! $gate->enabled('accounting')) {
            return false;
        }

        if (in_array($key, self::HR_REPORT_KEYS, true) && ! $gate->enabled('hr_payroll')) {
            return false;
        }

        if (in_array($key, self::INVENTORY_REPORT_KEYS, true) && ! $gate->enabled('inventory')) {
            return false;
        }

        if (in_array($key, ['open-lpo', 'purchases-by-supplier', 'supplier-returns'], true)
            && ! $gate->enabled('customers_suppliers')) {
            return false;
        }

        if (in_array($key, self::CUSTOMER_REPORT_KEYS, true) && ! $gate->enabled('customers_suppliers')) {
            return false;
        }

        if (in_array($key, self::SALES_REPORT_KEYS, true) && ! $gate->enabled('sales.backend')) {
            return false;
        }

        if ($key === 'kra-receipts' && ! $gate->enabled('accounting')) {
            return false;
        }

        if ($key === 'audit-trail' && ! $gate->enabled('admin')) {
            return false;
        }

        return true;
    }

    /** @param  array<string, mixed>  $item */
    protected function formatReportItem(string $key, array $item): array
    {
        $path = (string) ($item['path'] ?? "/reports/{$key}");
        $apiPath = ltrim($path, '/');
        $mobileParams = [];

        if ($key === 'items-currently-in-stock') {
            $apiPath = 'reports/stock-on-hand';
        } elseif ($key === 'customer-statement') {
            $apiPath = 'reports/customers/{customerNum}/statement';
            $mobileParams = [
                [
                    'key' => 'customer_num',
                    'label' => 'Customer',
                    'type' => 'customer_search',
                    'required' => true,
                ],
            ];
        } elseif (str_contains($apiPath, '{')) {
            $apiPath = '';
        } elseif (! str_starts_with($apiPath, 'reports/')) {
            $apiPath = "reports/{$key}";
        }

        return [
            'key' => $key,
            'label' => (string) ($item['label'] ?? $key),
            'path' => $path,
            'api_path' => $apiPath,
            'mobile_supported' => $apiPath !== '',
            'mobile_params' => $mobileParams,
        ];
    }

    protected function organizationHasMultipleBranches(User $user): bool
    {
        if (! $user->organization_id) {
            return false;
        }

        return Branch::query()
            ->where('organization_id', $user->organization_id)
            ->count() > 1;
    }

    protected function legacyArchiveEnabled(User $user, CapabilityGate $gate): bool
    {
        $org = $gate->organization() ?? $user->organization;
        if (! $org) {
            return false;
        }

        return app(OrganizationLegacyArchiveService::class)->forOrganization($org)['enabled'] ?? false;
    }
}
