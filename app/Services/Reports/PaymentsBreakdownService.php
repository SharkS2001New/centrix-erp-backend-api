<?php

namespace App\Services\Reports;

use App\Services\Pos\TillReportMetrics;
use App\Services\Sales\CentrixSalesScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order-level payments breakdown for till follow-up.
 *
 * Classification prefers sale_payments + payment_methods (Payment Module),
 * so admin-added banks (COOP, ABSA, NCBA, …) get their own “alone” tabs.
 * Sale cash/mpesa/equity/kcb columns are only a fallback when a paid sale
 * has no payment rows (legacy).
 */
class PaymentsBreakdownService
{
    /** Preferred tab order for known codes; other admin banks follow alphabetically. */
    private const METHOD_PRIORITY = [
        'CASH' => 0,
        'MPESA' => 1,
        'AIRTEL' => 2,
        'EQUITY' => 3,
        'KCB' => 4,
        'CARD' => 5,
        'BANK' => 6,
        'CHEQUE' => 7,
        'VOUCHER' => 8,
        'POINTS' => 9,
        'CREDIT' => 10,
        'OTHER' => 90,
        'MIXED' => 100,
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, int $organizationId, ?int $branchId = null): array
    {
        $fromDate = $this->nullableDate($request->input('from_date'));
        $toDate = $this->nullableDate($request->input('to_date'));
        $methodCode = $this->normalizeMethodCode((string) $request->input('method_code', ''));
        $search = trim((string) $request->input('q', ''));
        $cashierId = (int) $request->input('cashier_id', 0);
        if ($cashierId <= 0) {
            $cashierId = null;
        }
        $floatSessionId = (int) $request->input('float_session_id', 0);
        if ($floatSessionId <= 0) {
            $floatSessionId = null;
        }
        $sessionStatus = strtolower(trim((string) $request->input('session_status', 'all')));
        if (! in_array($sessionStatus, ['all', 'open', 'closed', 'suspended'], true)) {
            $sessionStatus = 'all';
        }
        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);

        $catalog = $this->paymentMethodCatalog($organizationId);

        $salesBase = $this->paidSalesQuery(
            $organizationId,
            $branchId,
            $fromDate,
            $toDate,
            $cashierId,
            $floatSessionId,
            $sessionStatus,
        );

        // Distinct tenders per sale from Payment Module rows (custom banks included).
        $paymentTenderSub = DB::table('sale_payments as sp')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->where('sp.amount', '>', TillReportMetrics::MIN_COLLECTED)
            ->select('sp.sale_id')
            ->selectRaw($this->normalizedMethodCodeSql('pm.method_code').' as method_code')
            ->selectRaw('COALESCE(SUM(sp.amount), 0) as method_amount')
            ->groupBy('sp.sale_id', DB::raw($this->normalizedMethodCodeSql('pm.method_code')));

        $paymentClassSub = DB::query()
            ->fromSub($paymentTenderSub, 'pt')
            ->select('pt.sale_id')
            ->selectRaw('COUNT(*) as tender_count')
            ->selectRaw("CASE WHEN COUNT(*) > 1 THEN 'MIXED' ELSE MAX(pt.method_code) END as alone_method")
            ->selectRaw('COALESCE(SUM(pt.method_amount), 0) as payments_total')
            ->groupBy('pt.sale_id');

        $classified = (clone $salesBase)
            ->leftJoinSub($paymentClassSub, 'pc', 'pc.sale_id', '=', 's.id')
            ->select([
                's.id',
                's.order_num',
                's.branch_id',
                's.channel',
                's.cashier_id',
                's.float_session_id',
                's.customer_num',
                's.customer_name_override',
                's.order_total',
                's.amount_paid',
                's.cash',
                's.mpesa_amount',
                's.equity_amount',
                's.kcb_amount',
                's.voucher_payment_amount',
                's.points_payment_amount',
                's.completed_at',
                's.created_at',
                DB::raw($this->aloneMethodWithFallbackSql().' as alone_method'),
                DB::raw($this->tenderCountWithFallbackSql().' as tender_count'),
                DB::raw('COALESCE(pc.payments_total, 0) as payments_total'),
            ])
            ->whereRaw('('.$this->aloneMethodWithFallbackSql().") <> 'NONE'");

        $methodRows = DB::query()
            ->fromSub($classified, 'x')
            ->select([
                'alone_method as method_code',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(COALESCE(amount_paid, order_total, 0)), 0) as total_amount'),
            ])
            ->groupBy('alone_method')
            ->get();

        $methods = $this->sortAndPresentMethods($methodRows, $catalog);

        if ($methodCode === '' && $methods !== []) {
            $methodCode = (string) $methods[0]['method_code'];
        }

        $active = collect($methods)->firstWhere('method_code', $methodCode);
        if (! $active && $methods !== []) {
            $methodCode = (string) $methods[0]['method_code'];
            $active = $methods[0];
        }

        $listQuery = DB::query()
            ->fromSub($classified, 'x')
            ->leftJoin('customers as c', function ($join) use ($organizationId) {
                $join->on('c.customer_num', '=', 'x.customer_num');
                if (Schema::hasColumn('customers', 'organization_id')) {
                    $join->where('c.organization_id', '=', $organizationId);
                }
            })
            ->leftJoin('users as u', 'u.id', '=', 'x.cashier_id')
            ->leftJoin('till_float_sessions as tfs', 'tfs.id', '=', 'x.float_session_id')
            ->leftJoin('tills as t', 't.id', '=', 'tfs.till_id');

        if ($methodCode !== '') {
            $listQuery->where('x.alone_method', $methodCode);
        } else {
            $listQuery->whereRaw('1 = 0');
        }

        if ($search !== '') {
            $listQuery->where(function ($inner) use ($search) {
                $inner->where('x.order_num', 'like', "%{$search}%")
                    ->orWhere('x.customer_name_override', 'like', "%{$search}%")
                    ->orWhere('c.customer_name', 'like', "%{$search}%")
                    ->orWhere('x.customer_num', 'like', "%{$search}%")
                    ->orWhereExists(function ($sub) use ($search) {
                        $sub->select(DB::raw(1))
                            ->from('sale_payments as sp')
                            ->whereColumn('sp.sale_id', 'x.id')
                            ->where('sp.reference_number', 'like', "%{$search}%");
                    });
            });
        }

        $summaryRaw = (clone $listQuery)
            ->reorder()
            ->select([
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(COALESCE(x.amount_paid, x.order_total, 0)), 0) as total_amount'),
            ])
            ->first();

        $paginator = $listQuery
            ->select([
                'x.id as sale_id',
                'x.order_num',
                'x.branch_id',
                'x.channel',
                'x.cashier_id',
                'x.float_session_id',
                'x.customer_num',
                'x.customer_name_override',
                'c.customer_name',
                'x.order_total',
                'x.amount_paid',
                'x.cash',
                'x.mpesa_amount',
                'x.equity_amount',
                'x.kcb_amount',
                'x.voucher_payment_amount',
                'x.points_payment_amount',
                'x.alone_method',
                'x.tender_count',
                'x.payments_total',
                'x.completed_at',
                'x.created_at',
                DB::raw('COALESCE(NULLIF(TRIM(u.full_name), ""), u.username) as cashier_name'),
                'tfs.status as session_status',
                'tfs.session_date',
                't.till_number',
                't.till_name',
            ])
            ->orderByDesc(DB::raw('COALESCE(x.completed_at, x.created_at)'))
            ->orderByDesc('x.id')
            ->paginate($perPage);

        $saleIds = collect($paginator->items())->pluck('sale_id')->filter()->map(fn ($id) => (int) $id)->all();
        $tendersBySale = $this->tendersFromPaymentsBySale($saleIds);
        $referencesBySale = $this->referencesBySale($saleIds);

        $rows = collect($paginator->items())->map(function ($row) use (
            $tendersBySale,
            $referencesBySale,
            $methodCode,
            $active,
            $catalog,
        ) {
            $saleId = (int) $row->sale_id;
            $customerName = trim((string) ($row->customer_name_override ?? ''))
                ?: trim((string) ($row->customer_name ?? ''))
                ?: null;
            $amountPaid = round((float) ($row->amount_paid ?? $row->order_total ?? 0), 2);
            $tenders = $tendersBySale[$saleId] ?? $this->tenderBreakdownFromColumns($row);
            $refs = $referencesBySale[$saleId] ?? [];
            $primaryRef = $refs[0] ?? null;

            $displayAmount = $methodCode === 'MIXED'
                ? $amountPaid
                : round((float) ($tenders[$methodCode] ?? $amountPaid), 2);

            $label = $active['method_name']
                ?? ($catalog[$methodCode]['method_name'] ?? null)
                ?? $this->methodLabel($methodCode, '');

            return [
                'sale_id' => $saleId,
                'payment_id' => $saleId,
                'order_num' => $row->order_num !== null ? (int) $row->order_num : null,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'channel' => $row->channel,
                'customer_num' => $row->customer_num !== null ? (int) $row->customer_num : null,
                'customer_name' => $customerName,
                'amount' => $displayAmount,
                'amount_paid' => $amountPaid,
                'order_total' => round((float) ($row->order_total ?? 0), 2),
                'tenders' => $tenders,
                'tender_count' => (int) ($row->tender_count ?? count($tenders)),
                'alone_method' => (string) ($row->alone_method ?? ''),
                'reference_number' => $primaryRef,
                'mpesa_code' => $primaryRef,
                'references' => $refs,
                'paid_at' => $row->completed_at ?? $row->created_at,
                'cashier_id' => $row->cashier_id !== null ? (int) $row->cashier_id : null,
                'cashier_name' => $row->cashier_name ? trim((string) $row->cashier_name) : null,
                'float_session_id' => $row->float_session_id !== null ? (int) $row->float_session_id : null,
                'session_status' => $row->session_status,
                'session_date' => $row->session_date,
                'till_number' => $row->till_number,
                'till_name' => $row->till_name,
                'method_code' => $methodCode,
                'method_name' => $label,
            ];
        })->values()->all();

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'cashier_id' => $cashierId,
            'float_session_id' => $floatSessionId,
            'session_status' => $sessionStatus,
            'method_code' => $methodCode !== '' ? $methodCode : null,
            'methods' => $methods,
            'sessions' => $this->sessionsForFilters(
                $organizationId,
                $branchId,
                $fromDate,
                $toDate,
                $cashierId,
            ),
            'data' => $rows,
            'summary' => [
                'method_code' => $active['method_code'] ?? $methodCode,
                'method_name' => $active['method_name'] ?? null,
                'payment_count' => (int) ($summaryRaw->order_count ?? 0),
                'order_count' => (int) ($summaryRaw->order_count ?? 0),
                'total_amount' => round((float) ($summaryRaw->total_amount ?? 0), 2),
                'grand_total' => round(array_sum(array_column($methods, 'total_amount')), 2),
            ],
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * Active Payment Module methods for this org (org-owned + platform/global).
     *
     * @return array<string, array{method_code: string, method_name: string, requires_reference: bool}>
     */
    protected function paymentMethodCatalog(int $organizationId): array
    {
        $q = DB::table('payment_methods')
            ->where('is_active', 1)
            ->where(function ($inner) use ($organizationId) {
                $inner->where('organization_id', $organizationId);
                if (Schema::hasColumn('payment_methods', 'organization_id')) {
                    $inner->orWhereNull('organization_id');
                }
            });

        $catalog = [];
        foreach ($q->orderBy('method_name')->get(['method_code', 'method_name', 'requires_reference']) as $row) {
            $code = $this->normalizeMethodCode((string) ($row->method_code ?? ''));
            if ($code === '' || $code === 'MIXED') {
                continue;
            }
            // Cheque / voucher / loyalty points are not shown on this breakdown.
            if (in_array($code, ['CHEQUE', 'VOUCHER', 'POINTS'], true)) {
                continue;
            }
            // Prefer org-specific name if both platform + org define the same code.
            if (! isset($catalog[$code]) || $catalog[$code]['method_name'] === '') {
                $catalog[$code] = [
                    'method_code' => $code,
                    'method_name' => $this->displayMethodName(
                        $code,
                        trim((string) ($row->method_name ?? '')),
                    ),
                    'requires_reference' => (bool) ($row->requires_reference ?? false),
                ];
            }
        }

        return $catalog;
    }

    protected function paidSalesQuery(
        int $organizationId,
        ?int $branchId,
        ?string $fromDate,
        ?string $toDate,
        ?int $cashierId,
        ?int $floatSessionId,
        string $sessionStatus,
    ) {
        $q = DB::table('sales as s')
            ->where('s.organization_id', $organizationId)
            ->whereIn('s.status', CentrixSalesScope::reportPipelineStatuses());

        CentrixSalesScope::excludeLegacyMaterialized($q, 's');
        app(TillReportMetrics::class)->applyCollectedSalesFilter($q, 's');

        if (Schema::hasColumn('sales', 'archived')) {
            $q->where('s.archived', 0);
        }
        if (Schema::hasColumn('sales', 'deleted_at')) {
            $q->whereNull('s.deleted_at');
        }

        if ($branchId) {
            $q->where('s.branch_id', $branchId);
        } else {
            $q->whereIn('s.branch_id', function ($sub) use ($organizationId) {
                $sub->select('id')
                    ->from('branches')
                    ->where('organization_id', $organizationId);
            });
        }

        if ($fromDate) {
            $q->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) >= ?', [$fromDate]);
        }
        if ($toDate) {
            $q->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) <= ?', [$toDate]);
        }
        if ($cashierId) {
            $q->where('s.cashier_id', $cashierId);
        }
        if ($floatSessionId) {
            $q->where('s.float_session_id', $floatSessionId);
        }

        if ($sessionStatus !== 'all') {
            $q->whereExists(function ($sub) use ($sessionStatus) {
                $sub->select(DB::raw(1))
                    ->from('till_float_sessions as tfs')
                    ->whereColumn('tfs.id', 's.float_session_id')
                    ->where('tfs.status', $sessionStatus);
            });
        }

        return $q;
    }

    /** Normalize method codes in SQL (M-PESA → MPESA, etc.). */
    protected function normalizedMethodCodeSql(string $expr): string
    {
        return "CASE
            WHEN UPPER(REPLACE(REPLACE(TRIM({$expr}), ' ', '_'), '-', '_')) IN ('M_PESA', 'MPESA') THEN 'MPESA'
            WHEN UPPER(REPLACE(REPLACE(TRIM({$expr}), ' ', '_'), '-', '_')) IN ('BANK_TRANSFER', 'TRANSFER') THEN 'BANK'
            ELSE UPPER(REPLACE(REPLACE(TRIM({$expr}), ' ', '_'), '-', '_'))
        END";
    }

    /**
     * Prefer Payment Module tenders; fall back to sale columns when no payment rows.
     */
    protected function aloneMethodWithFallbackSql(): string
    {
        return 'CASE
            WHEN pc.alone_method IS NOT NULL THEN pc.alone_method
            WHEN '.$this->columnTenderCountSql().' > 1 THEN \'MIXED\'
            WHEN COALESCE(s.cash, 0) > 0.009 THEN \'CASH\'
            WHEN COALESCE(s.mpesa_amount, 0) > 0.009 THEN \'MPESA\'
            WHEN COALESCE(s.equity_amount, 0) > 0.009 THEN \'EQUITY\'
            WHEN COALESCE(s.kcb_amount, 0) > 0.009 THEN \'KCB\'
            WHEN COALESCE(s.voucher_payment_amount, 0) > 0.009 THEN \'VOUCHER\'
            WHEN COALESCE(s.points_payment_amount, 0) > 0.009 THEN \'POINTS\'
            ELSE \'NONE\'
        END';
    }

    protected function tenderCountWithFallbackSql(): string
    {
        return 'CASE
            WHEN pc.tender_count IS NOT NULL THEN pc.tender_count
            ELSE '.$this->columnTenderCountSql().'
        END';
    }

    protected function columnTenderCountSql(): string
    {
        return '(
            CASE WHEN COALESCE(s.cash, 0) > 0.009 THEN 1 ELSE 0 END
            + CASE WHEN COALESCE(s.mpesa_amount, 0) > 0.009 THEN 1 ELSE 0 END
            + CASE WHEN COALESCE(s.equity_amount, 0) > 0.009 THEN 1 ELSE 0 END
            + CASE WHEN COALESCE(s.kcb_amount, 0) > 0.009 THEN 1 ELSE 0 END
            + CASE WHEN COALESCE(s.voucher_payment_amount, 0) > 0.009 THEN 1 ELSE 0 END
            + CASE WHEN COALESCE(s.points_payment_amount, 0) > 0.009 THEN 1 ELSE 0 END
        )';
    }

    /**
     * @param  list<int>  $saleIds
     * @return array<int, array<string, float>>
     */
    protected function tendersFromPaymentsBySale(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        $rows = DB::table('sale_payments as sp')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereIn('sp.sale_id', $saleIds)
            ->where('sp.amount', '>', TillReportMetrics::MIN_COLLECTED)
            ->select([
                'sp.sale_id',
                DB::raw($this->normalizedMethodCodeSql('pm.method_code').' as method_code'),
                DB::raw('COALESCE(SUM(sp.amount), 0) as total'),
            ])
            ->groupBy('sp.sale_id', DB::raw($this->normalizedMethodCodeSql('pm.method_code')))
            ->get();

        $bySale = [];
        foreach ($rows as $row) {
            $code = $this->normalizeMethodCode((string) ($row->method_code ?? ''));
            if ($code === '') {
                continue;
            }
            $saleId = (int) $row->sale_id;
            $bySale[$saleId] ??= [];
            $bySale[$saleId][$code] = round(
                ($bySale[$saleId][$code] ?? 0) + (float) ($row->total ?? 0),
                2,
            );
        }

        return $bySale;
    }

    /** @return array<string, float> */
    protected function tenderBreakdownFromColumns(object $row): array
    {
        $map = [
            'CASH' => (float) ($row->cash ?? 0),
            'MPESA' => (float) ($row->mpesa_amount ?? 0),
            'EQUITY' => (float) ($row->equity_amount ?? 0),
            'KCB' => (float) ($row->kcb_amount ?? 0),
            'VOUCHER' => (float) ($row->voucher_payment_amount ?? 0),
            'POINTS' => (float) ($row->points_payment_amount ?? 0),
        ];

        $out = [];
        foreach ($map as $code => $amount) {
            if ($amount > TillReportMetrics::MIN_COLLECTED) {
                $out[$code] = round($amount, 2);
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $saleIds
     * @return array<int, list<string>>
     */
    protected function referencesBySale(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }

        $rows = DB::table('sale_payments as sp')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereIn('sp.sale_id', $saleIds)
            ->whereNotNull('sp.reference_number')
            ->where('sp.reference_number', '!=', '')
            ->orderByDesc('sp.paid_at')
            ->orderByDesc('sp.id')
            ->get(['sp.sale_id', 'sp.reference_number', 'pm.method_code', 'pm.requires_reference']);

        $bySale = [];
        foreach ($rows as $row) {
            $saleId = (int) $row->sale_id;
            $ref = trim((string) $row->reference_number);
            if ($ref === '') {
                continue;
            }
            $bySale[$saleId] ??= [];
            if (! in_array($ref, $bySale[$saleId], true)) {
                $bySale[$saleId][] = $ref;
            }
        }

        return $bySale;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sessionsForFilters(
        int $organizationId,
        ?int $branchId,
        ?string $fromDate,
        ?string $toDate,
        ?int $cashierId,
    ): array {
        $q = DB::table('till_float_sessions as tfs')
            ->join('tills as t', 't.id', '=', 'tfs.till_id')
            ->join('users as u', 'u.id', '=', 'tfs.cashier_id')
            ->where('tfs.organization_id', $organizationId);

        if ($branchId) {
            $q->where('tfs.branch_id', $branchId);
        } else {
            $q->whereIn('tfs.branch_id', function ($sub) use ($organizationId) {
                $sub->select('id')->from('branches')->where('organization_id', $organizationId);
            });
        }
        if ($fromDate) {
            $q->whereDate('tfs.session_date', '>=', $fromDate);
        }
        if ($toDate) {
            $q->whereDate('tfs.session_date', '<=', $toDate);
        }
        if ($cashierId) {
            $q->where('tfs.cashier_id', $cashierId);
        }

        return $q
            ->orderByDesc('tfs.opened_at')
            ->orderByDesc('tfs.id')
            ->limit(100)
            ->get([
                'tfs.id',
                'tfs.status',
                'tfs.session_date',
                'tfs.opened_at',
                'tfs.closed_at',
                'tfs.cashier_id',
                'tfs.working_amount',
                't.till_number',
                't.till_name',
                DB::raw('COALESCE(NULLIF(TRIM(u.full_name), ""), u.username) as cashier_name'),
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'status' => (string) ($row->status ?? ''),
                'session_date' => $row->session_date,
                'opened_at' => $row->opened_at,
                'closed_at' => $row->closed_at,
                'cashier_id' => (int) ($row->cashier_id ?? 0),
                'cashier_name' => trim((string) ($row->cashier_name ?? '')),
                'working_amount' => round((float) ($row->working_amount ?? 0), 2),
                'till_number' => $row->till_number,
                'till_name' => $row->till_name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $methodRows
     * @param  array<string, array{method_code: string, method_name: string, requires_reference: bool}>  $catalog
     * @return list<array<string, mixed>>
     */
    protected function sortAndPresentMethods($methodRows, array $catalog): array
    {
        $hidden = ['CHEQUE', 'VOUCHER', 'POINTS', 'LOYALTY', 'LOYALTY_POINTS'];
        $byCode = [];
        foreach ($methodRows as $row) {
            $code = $this->normalizeMethodCode((string) ($row->method_code ?? ''));
            if ($code === '' || $code === 'NONE' || in_array($code, $hidden, true)) {
                continue;
            }
            $total = round((float) ($row->total_amount ?? 0), 2);
            $orders = (int) ($row->order_count ?? 0);
            // Hide empty methods from the breakdown tabs.
            if ($total <= 0 && $orders <= 0) {
                continue;
            }
            $name = $this->displayMethodName($code, $catalog[$code]['method_name'] ?? '');
            $byCode[$code] = [
                'method_code' => $code,
                'method_name' => $name,
                'payment_count' => $orders,
                'order_count' => $orders,
                'total_amount' => $total,
                'requires_reference' => (bool) ($catalog[$code]['requires_reference'] ?? false),
            ];
        }

        $methods = array_values($byCode);
        usort($methods, function (array $a, array $b) {
            $pa = self::METHOD_PRIORITY[$a['method_code']] ?? 40;
            $pb = self::METHOD_PRIORITY[$b['method_code']] ?? 40;
            if ($pa === $pb) {
                return strcmp($a['method_name'], $b['method_name']);
            }

            return $pa <=> $pb;
        });

        return $methods;
    }

    protected function displayMethodName(string $code, string $catalogName = ''): string
    {
        if ($code === 'MIXED') {
            return 'Mixed';
        }
        if ($code === 'CREDIT' || $code === 'DEBTORS' || $code === 'DEBTOR') {
            return 'Debtors';
        }
        $base = $catalogName !== '' ? $catalogName : $this->methodLabel($code, '');
        // Drop legacy "… alone" suffix for cleaner tabs.
        return (string) preg_replace('/\s+alone$/i', '', $base);
    }

    protected function aloneTabLabel(string $code, string $name): string
    {
        return $this->displayMethodName($code, $name);
    }

    protected function normalizeMethodCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'M_PESA', 'MPESA' => 'MPESA',
            'BANK_TRANSFER', 'TRANSFER' => 'BANK',
            'MIXED_PAYMENT', 'SPLIT', 'SPLIT_PAYMENT' => 'MIXED',
            'DEBTOR', 'DEBTORS', 'CREDITS' => 'CREDIT',
            'LOYALTY', 'LOYALTY_POINTS' => 'POINTS',
            default => $normalized,
        };
    }

    protected function methodLabel(string $code, string $name): string
    {
        if ($name !== '') {
            return $name;
        }

        return match ($code) {
            'CASH' => 'Cash',
            'MPESA' => 'M-Pesa',
            'EQUITY' => 'Equity',
            'KCB' => 'KCB',
            'CARD' => 'Card',
            'BANK' => 'Bank',
            'CREDIT' => 'Debtors',
            'MIXED' => 'Mixed',
            'OTHER' => 'Other',
            default => $code,
        };
    }

    protected function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
