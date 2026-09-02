<?php

namespace App\Services\Reports;

use App\Services\Hospitality\HospitalityPosRoomSaleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hotel F&B + POS room sales — dashboard payload matching retail /reports/eod-report shape.
 */
class HospitalityEodReportService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, int $organizationId, ?int $branchId = null): array
    {
        $data = $request->validate([
            'sale_date' => 'required_without:sale_month|date',
            'sale_month' => 'required_without:sale_date|date_format:Y-m',
            'branch_id' => 'nullable|integer',
            'cashier_id' => 'nullable|integer',
        ]);

        $cashierId = isset($data['cashier_id']) ? (int) $data['cashier_id'] : null;
        if ($cashierId <= 0) {
            $cashierId = null;
        }
        $branchId = $branchId ?? (isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        if ($branchId <= 0) {
            $branchId = null;
        }

        $isMonthly = ! empty($data['sale_month']);
        if ($isMonthly) {
            $periodStart = Carbon::parse($data['sale_month'].'-01')->startOfDay();
            $periodEnd = $periodStart->copy()->endOfMonth()->endOfDay();
            $date = $periodStart->toDateString();
        } else {
            $periodStart = Carbon::parse($data['sale_date'])->startOfDay();
            $periodEnd = $periodStart->copy()->endOfDay();
            $date = $data['sale_date'];
        }
        $periodStartDate = $periodStart->toDateString();
        $periodEndDate = $periodEnd->toDateString();

        $checksBase = DB::table('hospitality_checks as c')
            ->where('c.organization_id', $organizationId)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$periodStartDate, $periodEndDate]);
        $this->applyCheckFilters($checksBase, $branchId, $cashierId);

        $agg = (clone $checksBase)->selectRaw('
            COUNT(*) as transactions,
            COALESCE(SUM(c.total), 0) as gross_sales,
            COALESCE(SUM(c.vat_total), 0) as total_vat,
            COALESCE(SUM(c.amount_paid), 0) as amount_paid,
            MIN(COALESCE(c.closed_at, c.updated_at)) as first_sale_at,
            MAX(COALESCE(c.closed_at, c.updated_at)) as last_sale_at
        ')->first();

        $roomAgg = DB::table('hospitality_check_lines as l')
            ->join('hospitality_checks as c', 'c.id', '=', 'l.check_id')
            ->where('c.organization_id', $organizationId)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$periodStartDate, $periodEndDate])
            ->where('l.modifiers->type', HospitalityPosRoomSaleService::LINE_TYPE);
        $this->applyCheckFilters($roomAgg, $branchId, $cashierId, 'c');
        $roomSales = (float) (clone $roomAgg)->sum('l.line_total');
        $roomNights = (float) (clone $roomAgg)->sum('l.qty');

        $gross = round((float) ($agg->gross_sales ?? 0), 2);
        $totalVat = round((float) ($agg->total_vat ?? 0), 2);
        $roomSales = round($roomSales, 2);
        $fnbSales = round(max(0, $gross - $roomSales), 2);
        $netSales = $gross;
        $grossSalesExVat = max(0, round($gross - $totalVat, 2));
        $netSalesExVat = $grossSalesExVat;
        $transactions = (int) ($agg->transactions ?? 0);

        $payments = $this->aggregatePayments($organizationId, $periodStartDate, $periodEndDate, $branchId, $cashierId);
        $cash = round((float) ($payments['cash'] ?? 0), 2);
        $mpesa = round((float) ($payments['mpesa'] ?? 0), 2);
        $cardBank = round((float) ($payments['card_bank'] ?? 0), 2);
        $roomCharge = round((float) ($payments['room_charge'] ?? 0), 2);
        $other = round((float) ($payments['other'] ?? 0), 2);

        $voided = DB::table('hospitality_checks')
            ->where('organization_id', $organizationId)
            ->where('status', 'void')
            ->whereBetween(DB::raw('DATE(COALESCE(closed_at, updated_at))'), [$periodStartDate, $periodEndDate])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($cashierId, function ($q) use ($cashierId) {
                $q->where(function ($inner) use ($cashierId) {
                    $inner->where('closed_by', $cashierId)->orWhere('opened_by', $cashierId);
                });
            })
            ->count();

        $cashiers = $this->cashierRows($organizationId, $periodStartDate, $periodEndDate, $branchId);

        $dailyBreakdown = null;
        if ($isMonthly) {
            $dailyBreakdown = (clone $checksBase)
                ->selectRaw('
                    DATE(COALESCE(c.closed_at, c.updated_at)) as sale_date,
                    COUNT(*) as transactions,
                    COALESCE(SUM(c.total), 0) as gross_sales,
                    COALESCE(SUM(c.vat_total), 0) as total_vat
                ')
                ->groupBy(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'))
                ->orderBy('sale_date')
                ->get()
                ->map(function ($row) use ($organizationId, $branchId, $cashierId) {
                    $day = (string) $row->sale_date;
                    $dayPayments = $this->aggregatePayments($organizationId, $day, $day, $branchId, $cashierId);

                    return [
                        'sale_date' => $day,
                        'transactions' => (int) $row->transactions,
                        'gross_sales' => round((float) $row->gross_sales, 2),
                        'total_vat' => round((float) $row->total_vat, 2),
                        'cash_collected' => round((float) ($dayPayments['cash'] ?? 0), 2),
                    ];
                })
                ->values()
                ->all();
        }

        $branchName = $branchId
            ? DB::table('branches')->where('id', $branchId)->value('branch_name')
            : null;
        $cashierName = $cashierId
            ? DB::table('users')
                ->where('id', $cashierId)
                ->selectRaw('COALESCE(NULLIF(TRIM(full_name), ""), username) as name')
                ->value('name')
            : null;

        $paymentLines = array_values(array_filter([
            ['key' => 'cash', 'label' => 'Cash', 'total' => $cash, 'color' => '#185FA5'],
            ['key' => 'mpesa', 'label' => 'M-Pesa', 'total' => $mpesa, 'color' => '#059669'],
            ['key' => 'card_bank', 'label' => 'Card / Bank', 'total' => $cardBank, 'color' => '#7c3aed'],
            ['key' => 'room', 'label' => 'Charge to room', 'total' => $roomCharge, 'color' => '#d97706'],
            ['key' => 'other', 'label' => 'Other', 'total' => $other, 'color' => '#64748b'],
        ], fn (array $row) => (float) ($row['total'] ?? 0) > 0));

        return [
            'report_mode' => $isMonthly ? 'monthly' : 'daily',
            'sale_date' => $date,
            'sale_month' => $isMonthly ? $data['sale_month'] : null,
            'period_start' => $periodStartDate,
            'period_end' => $periodEndDate,
            'branch_id' => $branchId,
            'branch_name' => $branchName,
            'cashier_id' => $cashierId,
            'cashier_name' => $cashierName,
            'float_session_id' => null,
            'summary' => [
                'gross_sales' => $gross,
                'gross_sales_ex_vat' => $grossSalesExVat,
                'transactions' => $transactions,
                'total_discounts' => 0.0,
                'total_refunds' => 0.0,
                'net_sales' => $netSales,
                'net_sales_ex_vat' => $netSalesExVat,
                'total_vat' => $totalVat,
                'opening_float' => 0.0,
                'net_sales_minus_float' => $netSales,
                'expected_net_sales' => $netSales,
                'paid_debtors' => 0.0,
                'cash_movements_in' => 0.0,
                'cash_movements_out' => 0.0,
                'net_cash_expected' => $netSales,
                'session_expenses' => 0.0,
                'items_sold' => 0,
                'customers' => $transactions,
                'voided_transactions' => (int) $voided,
                'average_sale_value' => $transactions > 0 ? round($netSales / $transactions, 2) : 0.0,
                'start_time' => $agg->first_sale_at,
                'end_time' => $agg->last_sale_at,
                'room_sales' => $roomSales,
                'fnb_sales' => $fnbSales,
                'room_nights' => round($roomNights, 2),
                'amount_collected' => round((float) ($agg->amount_paid ?? 0), 2),
            ],
            'payments' => [
                'cash' => $cash,
                'mpesa' => $mpesa,
                'equity' => 0.0,
                'kcb' => 0.0,
                'bank' => $cardBank,
                'card' => $cardBank,
            ],
            'payment_lines' => $paymentLines,
            'tills' => [],
            'sessions' => [],
            'cashiers' => $cashiers,
            'float_breakdown' => [],
            'float_breakdown_total' => 0.0,
            'expenses' => [],
            'total_expenses' => 0.0,
            'session_expenses' => 0.0,
            'debtors' => [
                'opening' => null,
                'new_credit_sales' => 0.0,
                'payments_received' => 0.0,
                'closing' => 0.0,
            ],
            'net_position' => $netSales,
            'daily_breakdown' => $dailyBreakdown,
        ];
    }

    /**
     * @return array{cash: float, mpesa: float, card_bank: float, room_charge: float, other: float}
     */
    protected function aggregatePayments(
        int $organizationId,
        string $from,
        string $to,
        ?int $branchId,
        ?int $cashierId,
    ): array {
        $totals = [
            'cash' => 0.0,
            'mpesa' => 0.0,
            'card_bank' => 0.0,
            'room_charge' => 0.0,
            'other' => 0.0,
        ];

        $query = DB::table('hospitality_check_payments as p')
            ->join('hospitality_checks as c', 'c.id', '=', 'p.check_id')
            ->where('c.organization_id', $organizationId)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$from, $to])
            ->select('p.method_code', DB::raw('SUM(p.amount) as amt'))
            ->groupBy('p.method_code');
        $this->applyCheckFilters($query, $branchId, $cashierId, 'c');

        foreach ($query->get() as $row) {
            $amt = (float) $row->amt;
            $code = strtoupper((string) $row->method_code);
            if ($code === 'CASH') {
                $totals['cash'] += $amt;
            } elseif ($code === 'MPESA') {
                $totals['mpesa'] += $amt;
            } elseif ($code === 'ROOM') {
                $totals['room_charge'] += $amt;
            } elseif (in_array($code, ['CARD', 'EQUITY', 'KCB', 'BANK', 'OTHER', 'CHEQUE'], true)) {
                $totals['card_bank'] += $amt;
            } else {
                $totals['other'] += $amt;
            }
        }

        return $totals;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function cashierRows(
        int $organizationId,
        string $from,
        string $to,
        ?int $branchId,
    ): array {
        $checks = DB::table('hospitality_checks as c')
            ->where('c.organization_id', $organizationId)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$from, $to]);
        $this->applyCheckFilters($checks, $branchId, null, 'c');

        $rows = $checks
            ->selectRaw('COALESCE(c.closed_by, c.opened_by, 0) as cashier_id')
            ->selectRaw('COUNT(*) as transactions')
            ->selectRaw('COALESCE(SUM(c.total), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(c.vat_total), 0) as total_vat')
            ->groupBy(DB::raw('COALESCE(c.closed_by, c.opened_by, 0)'))
            ->orderByDesc('gross_sales')
            ->get();

        $userIds = $rows->pluck('cashier_id')->filter(fn ($id) => (int) $id > 0)->unique()->all();
        $names = $userIds
            ? DB::table('users')->whereIn('id', $userIds)->pluck('full_name', 'id')
            : collect();

        $paymentRows = DB::table('hospitality_check_payments as p')
            ->join('hospitality_checks as c', 'c.id', '=', 'p.check_id')
            ->where('c.organization_id', $organizationId)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$from, $to])
            ->selectRaw('COALESCE(c.closed_by, c.opened_by, 0) as cashier_id')
            ->selectRaw('UPPER(TRIM(COALESCE(p.method_code, ""))) as method_code')
            ->selectRaw('SUM(p.amount) as amt')
            ->groupBy(DB::raw('COALESCE(c.closed_by, c.opened_by, 0)'), DB::raw('UPPER(TRIM(COALESCE(p.method_code, "")))'));
        $this->applyCheckFilters($paymentRows, $branchId, null, 'c');

        $paymentsByCashier = [];
        foreach ($paymentRows->get() as $payment) {
            $cid = (int) $payment->cashier_id;
            if (! isset($paymentsByCashier[$cid])) {
                $paymentsByCashier[$cid] = [
                    'cash_collected' => 0.0,
                    'mpesa_collected' => 0.0,
                    'bank_collected' => 0.0,
                    'room_charge' => 0.0,
                ];
            }
            $amt = (float) $payment->amt;
            $code = (string) $payment->method_code;
            if ($code === 'CASH') {
                $paymentsByCashier[$cid]['cash_collected'] += $amt;
            } elseif ($code === 'MPESA') {
                $paymentsByCashier[$cid]['mpesa_collected'] += $amt;
            } elseif ($code === 'ROOM') {
                $paymentsByCashier[$cid]['room_charge'] += $amt;
            } elseif (in_array($code, ['CARD', 'EQUITY', 'KCB', 'BANK', 'OTHER', 'CHEQUE'], true)) {
                $paymentsByCashier[$cid]['bank_collected'] += $amt;
            }
        }

        return $rows->map(function ($row) use ($names, $paymentsByCashier) {
            $cid = (int) $row->cashier_id;
            $pay = $paymentsByCashier[$cid] ?? [
                'cash_collected' => 0.0,
                'mpesa_collected' => 0.0,
                'bank_collected' => 0.0,
                'room_charge' => 0.0,
            ];

            return [
                'cashier_id' => $cid ?: null,
                'cashier' => $cid ? (string) ($names[$cid] ?? ('User #'.$cid)) : 'Unassigned',
                'gross_sales' => round((float) $row->gross_sales, 2),
                'total_vat' => round((float) $row->total_vat, 2),
                'transactions' => (int) $row->transactions,
                'cash_collected' => round($pay['cash_collected'], 2),
                'mpesa_collected' => round($pay['mpesa_collected'], 2),
                'equity_collected' => 0.0,
                'kcb_collected' => 0.0,
                'bank_collected' => round($pay['bank_collected'], 2),
                'room_charge' => round($pay['room_charge'], 2),
                'opening_float' => 0.0,
            ];
        })->values()->all();
    }

    protected function applyCheckFilters($query, ?int $branchId, ?int $cashierId, string $alias = 'c'): void
    {
        if ($branchId) {
            $query->where("{$alias}.branch_id", $branchId);
        }
        if ($cashierId) {
            $query->where(function ($q) use ($cashierId, $alias) {
                $q->where("{$alias}.closed_by", $cashierId)
                    ->orWhere("{$alias}.opened_by", $cashierId);
            });
        }
    }
}
