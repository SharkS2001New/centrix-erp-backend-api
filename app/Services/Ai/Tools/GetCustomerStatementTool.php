<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiNearMissHelper;
use App\Services\Ai\AiQtyLabelEnricher;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Customer statement for AI chat: balance + period purchases with line items.
 */
class GetCustomerStatementTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected AiQtyLabelEnricher $qtyLabels,
    ) {}

    public function name(): string
    {
        return 'get_customer_statement';
    }

    public function description(): string
    {
        return 'Get a Centrix customer statement for a named customer: current balance due, period invoices/orders, '
            .'payments, and what they bought (product line items with qty_label, unit price, and amounts). '
            .'Use for "customer statement", "what did they buy", "balance for @Customer", or month statements '
            .'(pass month=august and year=2026, or year_month=2026-08, or from_date/to_date). '
            .'Never say you lack line-item access — this tool returns purchases. Always call it for statement questions.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_num' => [
                    'type' => 'string',
                    'description' => 'Exact customer number / code when known (from @Customer mention).',
                ],
                'customer_name' => [
                    'type' => 'string',
                    'description' => 'Customer name search when customer_num is unknown.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Fallback search (name or customer_num).',
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
                'relative_date' => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month'],
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Period start YYYY-MM-DD.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Period end YYYY-MM-DD.',
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
            || $this->permissions->hasPermission($user, 'customers.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.customer_statement.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate)
            || $this->permissions->hasPermission($user, 'accounting.ar.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view customer statements.',
                'screens' => $this->screens(null),
            ];
        }

        $orgId = (int) $organization->id;
        $resolved = $this->resolveCustomer($orgId, $arguments);
        if (($resolved['error'] ?? false) === true) {
            return array_merge($resolved, ['screens' => $this->screens(null)]);
        }

        /** @var Customer $customer */
        $customer = $resolved['customer'];
        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $customerNum = (int) $customer->customer_num;

        $orders = $this->ordersInPeriod($orgId, $customerNum, $from, $to);
        $orderIds = collect($orders)->pluck('sale_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $lineItems = $this->lineItemsForSales($orgId, $orderIds);
        $purchasesByProduct = $this->aggregatePurchases($orgId, $lineItems);
        $payments = $this->paymentsInPeriod($orgId, $customerNum, $from, $to);
        $invoices = $this->invoicesInPeriod($orgId, $customerNum, $from, $to);

        $periodPurchases = round(array_sum(array_map(fn ($o) => (float) ($o['order_total'] ?? 0), $orders)), 2);
        $periodPaid = round(array_sum(array_map(fn ($p) => (float) ($p['amount_paid'] ?? 0), $payments)), 2);
        $openOrders = array_values(array_filter(
            $orders,
            fn ($o) => in_array(($o['payment_status'] ?? ''), ['unpaid', 'partial', 'partially_paid', 'pending'], true)
                || ((float) ($o['balance_due'] ?? 0) > 0.009),
        ));

        $invoiceDates = collect($invoices)->pluck('invoice_date')->filter()->sort()->values();
        $balance = round((float) ($customer->current_balance ?? 0), 2);

        return [
            'currency' => 'KES',
            'period' => [
                'from_date' => $from,
                'to_date' => $to,
                'label' => $this->periodLabel($from, $to),
            ],
            'customer' => [
                'customer_num' => $customerNum,
                'customer_name' => (string) $customer->customer_name,
                'phone' => $customer->phone_number,
                'credit_limit' => $customer->credit_limit !== null ? (float) $customer->credit_limit : null,
                'current_balance' => $balance,
                'terms_of_payment' => $customer->terms_of_payment,
                'profile_path' => '/customers/'.$customerNum,
            ],
            'summary' => [
                'current_balance_due' => $balance,
                'period_purchases_total' => $periodPurchases,
                'period_payments_total' => $periodPaid,
                'orders_in_period' => count($orders),
                'open_orders_count' => count($openOrders),
                'oldest_invoice_date' => $invoiceDates->first(),
                'latest_invoice_date' => $invoiceDates->last(),
            ],
            'orders' => $orders,
            'purchases_by_product' => $purchasesByProduct,
            'line_items' => $lineItems,
            'invoices' => $invoices,
            'payments' => $payments,
            'screens' => $this->screens($customerNum),
            'tip' => 'Answer with the customer name and balance, then a markdown table of what they bought (product name, qty_label, amount) — never customer_num or product_code columns. '
                .'Do not claim you lack line-item access — purchases_by_product and line_items are the statement detail. '
                .'Quote amounts in KES exactly as returned.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{error?: bool, message?: string, candidates?: list<array<string, mixed>>, customer?: Customer}
     */
    protected function resolveCustomer(int $organizationId, array $arguments): array
    {
        $numRaw = trim((string) ($arguments['customer_num'] ?? ''));
        $name = trim((string) ($arguments['customer_name'] ?? $arguments['query'] ?? ''));

        if ($numRaw !== '' && is_numeric($numRaw)) {
            $customer = Customer::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->where('customer_num', (int) $numRaw)
                ->first();
            if ($customer) {
                return ['customer' => $customer];
            }
        }

        $needle = $name !== '' ? $name : $numRaw;
        if ($needle === '') {
            return [
                'error' => true,
                'message' => 'Provide customer_num or customer_name (e.g. from an @Customer mention).',
            ];
        }

        $matches = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($needle) {
                $q->where('customer_name', 'like', '%'.$needle.'%');
                if (is_numeric($needle)) {
                    $q->orWhere('customer_num', (int) $needle);
                } else {
                    $q->orWhere('customer_num', 'like', '%'.$needle.'%');
                }
            })
            ->orderBy('customer_name')
            ->limit(10)
            ->get();

        if ($matches->isEmpty()) {
            $relaxed = $this->relaxedCustomerMatches($organizationId, $needle);
            if ($relaxed->isEmpty()) {
                return AiNearMissHelper::noExact(
                    $needle,
                    null,
                    [],
                    $this->screens(null),
                    'Try a shorter name, customer number, or open View Customers to confirm the record exists.',
                );
            }

            $ranked = $relaxed->map(function (Customer $c) use ($needle) {
                $label = (string) $c->customer_name;

                return [
                    'label' => $label,
                    'reason' => AiNearMissHelper::matchReason($needle, $label),
                    'score' => AiNearMissHelper::scoreNameMatch($needle, $label),
                    'customer_num' => (int) $c->customer_num,
                ];
            })->sortByDesc('score')->values()->all();

            ['closest' => $closest, 'alternatives' => $alternatives] = AiNearMissHelper::splitRankedMatches($ranked);
            if ($closest !== null && isset($ranked[0]['customer_num'])) {
                $closest['customer_num'] = $ranked[0]['customer_num'];
            }

            return AiNearMissHelper::noExact(
                $needle,
                $closest,
                $alternatives,
                $this->screens(null),
                'Reply with the exact customer name or number and I can pull their statement.',
            );
        }
        if ($matches->count() === 1) {
            return ['customer' => $matches->first()];
        }

        return AiNearMissHelper::ambiguous(
            $needle,
            $matches->map(fn (Customer $c) => [
                'label' => (string) $c->customer_name,
                'customer_num' => (int) $c->customer_num,
                'current_balance' => round((float) ($c->current_balance ?? 0), 2),
            ])->all(),
            'customer',
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Customer>
     */
    protected function relaxedCustomerMatches(int $organizationId, string $needle)
    {
        $tokens = AiNearMissHelper::tokens($needle);
        if ($tokens === []) {
            return collect();
        }

        return Customer::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    if (mb_strlen($token) < 3) {
                        continue;
                    }
                    $like = '%'.$token.'%';
                    $q->orWhere('customer_name', 'like', $like);
                }
            })
            ->orderBy('customer_name')
            ->limit(10)
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function ordersInPeriod(int $orgId, int $customerNum, string $from, string $to): array
    {
        return DB::table('sales')
            ->where('organization_id', $orgId)
            ->where('customer_num', $customerNum)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, $to])
            ->orderBy('completed_at')
            ->orderBy('id')
            ->limit(80)
            ->get([
                'id',
                'order_num',
                'status',
                'payment_status',
                'is_credit_sale',
                'order_total',
                'amount_paid',
                'completed_at',
                'created_at',
            ])
            ->map(function ($row) {
                $total = (float) ($row->order_total ?? 0);
                $paid = (float) ($row->amount_paid ?? 0);

                return [
                    'sale_id' => (int) $row->id,
                    'order_num' => (int) $row->order_num,
                    'order_date' => optional($row->completed_at ? \Carbon\Carbon::parse($row->completed_at) : null)?->toDateString()
                        ?? optional($row->created_at ? \Carbon\Carbon::parse($row->created_at) : null)?->toDateString(),
                    'status' => (string) ($row->status ?? ''),
                    'payment_status' => (string) ($row->payment_status ?? ''),
                    'is_credit_sale' => (bool) ($row->is_credit_sale ?? false),
                    'order_total' => round($total, 2),
                    'amount_paid' => round($paid, 2),
                    'balance_due' => round(max(0, $total - $paid), 2),
                    'order_path' => '/sales/orders/'.$row->id,
                ];
            })
            ->all();
    }

    /**
     * @param  list<int>  $saleIds
     * @return list<array<string, mixed>>
     */
    protected function lineItemsForSales(int $orgId, array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        $rows = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('products as p', function ($join) use ($orgId) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->where('p.organization_id', '=', $orgId);
            })
            ->whereIn('si.sale_id', $saleIds)
            ->orderBy('s.completed_at')
            ->orderBy('si.sale_id')
            ->orderBy('si.line_no')
            ->limit(400)
            ->get([
                'si.sale_id',
                's.order_num',
                's.completed_at',
                'si.product_code',
                'si.product_name',
                'p.product_name as catalog_product_name',
                'si.quantity',
                'si.selling_price',
                'si.discount_given',
                'si.amount',
            ])
            ->map(function ($row) {
                $name = trim((string) ($row->product_name ?? ''));
                $catalog = trim((string) ($row->catalog_product_name ?? ''));
                if ($name === '' || $name === (string) $row->product_code) {
                    $name = $catalog !== '' ? $catalog : (string) $row->product_code;
                }

                return [
                    'sale_id' => (int) $row->sale_id,
                    'order_num' => (int) $row->order_num,
                    'order_date' => optional($row->completed_at ? \Carbon\Carbon::parse($row->completed_at) : null)?->toDateString(),
                    'product_code' => (string) $row->product_code,
                    'product_name' => $name,
                    'qty' => (float) $row->quantity,
                    'unit_price' => round((float) ($row->selling_price ?? 0), 4),
                    'discount' => round((float) ($row->discount_given ?? 0), 2),
                    'amount' => round((float) ($row->amount ?? 0), 2),
                ];
            })
            ->all();

        return $this->qtyLabels->enrichProductRows($orgId, $rows, 'qty');
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return list<array<string, mixed>>
     */
    protected function aggregatePurchases(int $orgId, array $lineItems): array
    {
        $map = [];
        foreach ($lineItems as $line) {
            $code = (string) ($line['product_code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (! isset($map[$code])) {
                $map[$code] = [
                    'product_code' => $code,
                    'product_name' => (string) ($line['product_name'] ?? $code),
                    'qty' => 0.0,
                    'amount' => 0.0,
                ];
            }
            $map[$code]['qty'] += (float) ($line['qty'] ?? 0);
            $map[$code]['amount'] += (float) ($line['amount'] ?? 0);
        }

        $rows = array_values(array_map(function (array $row) {
            $row['qty'] = round($row['qty'], 4);
            $row['amount'] = round($row['amount'], 2);

            return $row;
        }, $map));

        usort($rows, fn ($a, $b) => ($b['amount'] <=> $a['amount']));

        return $this->qtyLabels->enrichProductRows($orgId, array_slice($rows, 0, 80), 'qty');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function invoicesInPeriod(int $orgId, int $customerNum, string $from, string $to): array
    {
        if (! Schema::hasTable('customer_invoices')) {
            return [];
        }

        $query = DB::table('customer_invoices')
            ->where('customer_num', $customerNum)
            ->whereNull('deleted_at')
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to);
        if (Schema::hasColumn('customer_invoices', 'organization_id')) {
            $query->where('organization_id', $orgId);
        }

        return $query->orderBy('invoice_date')
            ->limit(80)
            ->get()
            ->map(fn ($row) => [
                'invoice_number' => $row->invoice_number ?? null,
                'invoice_date' => (string) ($row->invoice_date ?? ''),
                'invoice_total' => isset($row->invoice_total) ? round((float) $row->invoice_total, 2) : null,
                'amount_paid' => isset($row->amount_paid) ? round((float) $row->amount_paid, 2) : null,
                'balance_due' => isset($row->balance_due)
                    ? round((float) $row->balance_due, 2)
                    : (isset($row->invoice_total, $row->amount_paid)
                        ? round((float) $row->invoice_total - (float) $row->amount_paid, 2)
                        : null),
                'status' => $row->payment_status ?? $row->status ?? null,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function paymentsInPeriod(int $orgId, int $customerNum, string $from, string $to): array
    {
        if (! Schema::hasTable('customer_invoice_payments')) {
            return [];
        }

        $query = DB::table('customer_invoice_payments')
            ->where('customer_num', $customerNum)
            ->whereDate('date_paid', '>=', $from)
            ->whereDate('date_paid', '<=', $to);
        if (Schema::hasColumn('customer_invoice_payments', 'organization_id')) {
            $query->where('organization_id', $orgId);
        }

        return $query->orderBy('date_paid')
            ->limit(80)
            ->get()
            ->map(fn ($row) => [
                'date_paid' => (string) ($row->date_paid ?? ''),
                'amount_paid' => round((float) ($row->amount_paid ?? 0), 2),
                'payment_method' => $row->payment_method ?? $row->method_name ?? null,
                'reference' => $row->reference ?? $row->payment_reference ?? null,
            ])
            ->all();
    }

    protected function periodLabel(string $from, string $to): string
    {
        try {
            $start = \Carbon\Carbon::parse($from);
            $end = \Carbon\Carbon::parse($to);
            if ($start->isSameMonth($end) && $start->day === 1 && $end->isSameDay($start->copy()->endOfMonth())) {
                return $start->format('F Y');
            }

            return $from.' to '.$to;
        } catch (\Throwable) {
            return $from.' to '.$to;
        }
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(?int $customerNum): array
    {
        $screens = [
            ['label' => 'Customer statement report', 'path' => '/reports/customer-statement'],
            ['label' => 'Accounts receivable', 'path' => '/accounting/accounts-receivable'],
            ['label' => 'Sales orders', 'path' => '/sales/orders'],
        ];
        if ($customerNum) {
            array_unshift($screens, [
                'label' => 'Customer profile',
                'path' => '/customers/'.$customerNum,
            ]);
        }

        return $screens;
    }
}
