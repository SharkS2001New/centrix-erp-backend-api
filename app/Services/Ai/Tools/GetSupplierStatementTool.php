<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiNearMissHelper;
use App\Services\Ai\AiQtyLabelEnricher;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\LpoModuleService;
use App\Services\SupplierModuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Supplier statement for AI chat: balance + period LPOs/payments + what was ordered/received.
 */
class GetSupplierStatementTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected SupplierModuleService $suppliers,
        protected LpoModuleService $lpoModule,
        protected AiQtyLabelEnricher $qtyLabels,
    ) {}

    public function name(): string
    {
        return 'get_supplier_statement';
    }

    public function description(): string
    {
        return 'Get a Centrix supplier statement for a named supplier: balance due (AP), period LPOs/purchases, '
            .'payments, and what was ordered/received (product line items with qty_label, cost, and amounts). '
            .'Use for "supplier statement", "what did we buy from @Supplier", "supplier balance", or month statements '
            .'(month=august year=2026, year_month=2026-08, or from_date/to_date). '
            .'Never say you lack line-item access — this tool returns purchase lines. Always call it for supplier statement questions.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => [
                    'type' => 'integer',
                    'description' => 'Exact supplier id when known (from @Supplier mention).',
                ],
                'supplier_code' => [
                    'type' => 'string',
                    'description' => 'Supplier code (e.g. SUP-001).',
                ],
                'supplier_name' => [
                    'type' => 'string',
                    'description' => 'Supplier name search when id/code is unknown.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Fallback search (name or code).',
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
            || $this->permissions->hasPermission($user, 'purchasing.suppliers.view', $gate)
            || $this->permissions->hasPermission($user, 'purchasing.lpo.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'accounting.ap.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view supplier statements.',
                'screens' => $this->screens(null),
            ];
        }

        $orgId = (int) $organization->id;
        $resolved = $this->resolveSupplier($orgId, $arguments);
        if (($resolved['error'] ?? false) === true) {
            return array_merge($resolved, ['screens' => $this->screens(null)]);
        }

        /** @var Supplier $supplier */
        $supplier = $resolved['supplier'];
        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);

        $full = $this->suppliers->summary($supplier);
        $purchases = $this->filterPurchasesByPeriod($full['purchases'] ?? [], $from, $to);
        $payments = $this->filterPaymentsByPeriod($full['payments'] ?? [], $from, $to);
        $lpoNos = collect($purchases)->pluck('lpo_no')->map(fn ($n) => (int) $n)->filter()->unique()->values()->all();
        $lineItems = $this->lineItemsForLpos($orgId, $lpoNos);
        $purchasesByProduct = $this->aggregatePurchases($orgId, $lineItems);

        $periodPurchases = round(array_sum(array_map(
            fn ($row) => (float) ($row['net_amount'] ?? $row['total_amount'] ?? 0),
            $purchases,
        )), 2);
        $periodPaid = round(array_sum(array_map(
            fn ($row) => (float) ($row['amount_paid'] ?? 0),
            $payments,
        )), 2);
        $openCount = count(array_filter(
            $purchases,
            fn ($row) => ((float) ($row['balance_due'] ?? 0)) > 0.009,
        ));
        $balance = round((float) ($full['supplier']['current_balance'] ?? 0), 2);

        $orderDates = collect($purchases)
            ->map(fn ($row) => $this->asDateString($row['order_date'] ?? null))
            ->filter()
            ->sort()
            ->values();

        return [
            'currency' => 'KES',
            'period' => [
                'from_date' => $from,
                'to_date' => $to,
                'label' => $this->periodLabel($from, $to),
            ],
            'supplier' => [
                'supplier_id' => (int) $supplier->id,
                'supplier_code' => $supplier->supplier_code,
                'supplier_name' => (string) $supplier->supplier_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'town' => $supplier->town,
                'tax_pin' => $supplier->tax_pin,
                'terms_of_payment' => $supplier->terms_of_payment,
                'current_balance' => $balance,
                'profile_path' => '/suppliers/'.$supplier->id,
            ],
            'summary' => [
                'current_balance_due' => $balance,
                'period_purchases_total' => $periodPurchases,
                'period_payments_total' => $periodPaid,
                'lpos_in_period' => count($purchases),
                'open_lpo_count' => $openCount,
                'lifetime_total_purchases' => round((float) ($full['stats']['total_purchases'] ?? 0), 2),
                'lifetime_total_paid' => round((float) ($full['stats']['total_paid'] ?? 0), 2),
                'oldest_lpo_date' => $orderDates->first(),
                'latest_lpo_date' => $orderDates->last(),
            ],
            'purchases' => array_map(function (array $row) {
                $row['order_date'] = $this->asDateString($row['order_date'] ?? null);
                $row['lpo_path'] = '/lpo/'.(int) ($row['lpo_no'] ?? 0);

                return $row;
            }, $purchases),
            'payments' => $payments,
            'purchases_by_product' => $purchasesByProduct,
            'line_items' => $lineItems,
            'screens' => $this->screens((int) $supplier->id),
            'tip' => 'Answer with balance due, then markdown tables of purchases (LPOs) and purchases_by_product '
                .'(what was bought: product, qty_label, amount). Never claim you lack line-item access when line_items is present. '
                .'Quote amounts in KES exactly as returned.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{error?: bool, message?: string, candidates?: list<array<string, mixed>>, supplier?: Supplier}
     */
    protected function resolveSupplier(int $organizationId, array $arguments): array
    {
        $id = (int) ($arguments['supplier_id'] ?? 0);
        if ($id > 0) {
            $supplier = Supplier::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->whereKey($id)
                ->first();
            if ($supplier) {
                return ['supplier' => $supplier];
            }
        }

        $code = trim((string) ($arguments['supplier_code'] ?? ''));
        if ($code !== '') {
            $supplier = Supplier::query()
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->where('supplier_code', $code)
                ->first();
            if ($supplier) {
                return ['supplier' => $supplier];
            }
        }

        $needle = trim((string) ($arguments['supplier_name'] ?? $arguments['query'] ?? $code));
        if ($needle === '') {
            return [
                'error' => true,
                'message' => 'Provide supplier_id, supplier_code, or supplier_name (e.g. from an @Supplier mention).',
            ];
        }

        $matches = Supplier::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($needle) {
                $q->where('supplier_name', 'like', '%'.$needle.'%')
                    ->orWhere('supplier_code', 'like', '%'.$needle.'%');
                if (is_numeric($needle)) {
                    $q->orWhere('id', (int) $needle);
                }
            })
            ->orderBy('supplier_name')
            ->limit(10)
            ->get();

        if ($matches->isEmpty()) {
            $relaxed = $this->relaxedSupplierMatches($organizationId, $needle);
            if ($relaxed->isEmpty()) {
                return AiNearMissHelper::noExact(
                    $needle,
                    null,
                    [],
                    $this->screens(null),
                    'Try a shorter supplier name or open Suppliers to confirm the record.',
                );
            }

            $ranked = $relaxed->map(function (Supplier $s) use ($needle) {
                $label = (string) $s->supplier_name;

                return [
                    'label' => $label,
                    'reason' => AiNearMissHelper::matchReason($needle, $label),
                    'score' => AiNearMissHelper::scoreNameMatch($needle, $label),
                    'supplier_id' => (int) $s->id,
                ];
            })->sortByDesc('score')->values()->all();

            ['closest' => $closest, 'alternatives' => $alternatives] = AiNearMissHelper::splitRankedMatches($ranked);

            return AiNearMissHelper::noExact(
                $needle,
                $closest,
                $alternatives,
                $this->screens(null),
                'Reply with the exact supplier name or code and I can pull their statement.',
            );
        }
        if ($matches->count() === 1) {
            return ['supplier' => $matches->first()];
        }

        $balances = $this->suppliers->balancesForSuppliers($matches->pluck('id'), $organizationId);

        return AiNearMissHelper::ambiguous(
            $needle,
            $matches->map(fn (Supplier $s) => [
                'label' => (string) $s->supplier_name,
                'supplier_id' => (int) $s->id,
                'supplier_code' => $s->supplier_code,
                'current_balance' => round((float) ($balances[(int) $s->id] ?? 0), 2),
            ])->all(),
            'supplier',
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Supplier>
     */
    protected function relaxedSupplierMatches(int $organizationId, string $needle)
    {
        $tokens = AiNearMissHelper::tokens($needle);
        if ($tokens === []) {
            return collect();
        }

        return Supplier::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    if (mb_strlen($token) < 3) {
                        continue;
                    }
                    $like = '%'.$token.'%';
                    $q->orWhere('supplier_name', 'like', $like)
                        ->orWhere('supplier_code', 'like', $like);
                }
            })
            ->orderBy('supplier_name')
            ->limit(10)
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $purchases
     * @return list<array<string, mixed>>
     */
    protected function filterPurchasesByPeriod(array $purchases, string $from, string $to): array
    {
        return array_values(array_filter($purchases, function (array $row) use ($from, $to) {
            $date = $this->asDateString($row['order_date'] ?? null);
            if ($date === null) {
                return false;
            }

            return $date >= $from && $date <= $to;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return list<array<string, mixed>>
     */
    protected function filterPaymentsByPeriod(array $payments, string $from, string $to): array
    {
        return array_values(array_filter($payments, function (array $row) use ($from, $to) {
            $date = $this->asDateString($row['date_paid'] ?? null);
            if ($date === null) {
                return false;
            }

            return $date >= $from && $date <= $to;
        }));
    }

    /**
     * @param  list<int>  $lpoNos
     * @return list<array<string, mixed>>
     */
    protected function lineItemsForLpos(int $orgId, array $lpoNos): array
    {
        if ($lpoNos === [] || ! Schema::hasTable('lpo_txn')) {
            return [];
        }

        $rows = DB::table('lpo_txn as t')
            ->join('lpo_mst as m', 'm.lpo_no', '=', 't.lpo_no')
            ->leftJoin('products as p', function ($join) use ($orgId) {
                $join->on('p.product_code', '=', 't.product_code')
                    ->where('p.organization_id', '=', $orgId);
            })
            ->whereIn('t.lpo_no', $lpoNos)
            ->whereNull('m.deleted_at')
            ->orderBy('t.lpo_no')
            ->orderBy('t.product_code')
            ->limit(400)
            ->get([
                't.lpo_no',
                'm.lpo_seq',
                'm.created_at',
                'm.sent_at',
                't.product_code',
                'p.product_name',
                't.ordered_qty',
                't.received_qty',
                't.cost_price',
                't.uom',
            ])
            ->map(function ($row) {
                $ordered = (float) ($row->ordered_qty ?? 0);
                $received = (float) ($row->received_qty ?? 0);
                $qty = $received > 0 ? $received : $ordered;
                $cost = (float) ($row->cost_price ?? 0);
                $seq = (int) ($row->lpo_seq ?? $row->lpo_no);
                $orderDate = $row->created_at ?? $row->sent_at;

                return [
                    'lpo_no' => (int) $row->lpo_no,
                    'po_number' => $this->lpoModule->formatPoNumber($seq, $orderDate),
                    'order_date' => $this->asDateString($orderDate),
                    'product_code' => (string) $row->product_code,
                    'product_name' => trim((string) ($row->product_name ?? '')) !== ''
                        ? (string) $row->product_name
                        : (string) $row->product_code,
                    'qty' => $qty,
                    'ordered_qty' => $ordered,
                    'received_qty' => $received,
                    'unit_cost' => round($cost, 4),
                    'amount' => round($qty * $cost, 2),
                    'uom' => $row->uom,
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

    protected function asDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return is_string($value) ? substr($value, 0, 10) : null;
        }
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
    protected function screens(?int $supplierId): array
    {
        $screens = [
            ['label' => 'Supplier statement report', 'path' => '/reports/supplier-statement'],
            ['label' => 'Supplier payments', 'path' => '/suppliers/payments'],
            ['label' => 'Purchase orders (LPO)', 'path' => '/lpo'],
            ['label' => 'Accounts payable', 'path' => '/reports/accounts-payable'],
        ];
        if ($supplierId) {
            array_unshift($screens, [
                'label' => 'Supplier profile',
                'path' => '/suppliers/'.$supplierId,
            ]);
            $screens[1] = [
                'label' => 'Supplier statement report',
                'path' => '/reports/supplier-statement?supplier_id='.$supplierId,
            ];
        }

        return $screens;
    }
}
