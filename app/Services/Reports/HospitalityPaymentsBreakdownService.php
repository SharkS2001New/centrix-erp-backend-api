<?php

namespace App\Services\Reports;

use App\Support\SqlLikeSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * F&B check payments breakdown for Hotel Backoffice — tender tabs like retail Payments breakdown.
 */
class HospitalityPaymentsBreakdownService
{
    private const METHOD_PRIORITY = [
        'CASH' => 0,
        'MPESA' => 1,
        'EQUITY' => 2,
        'KCB' => 3,
        'CARD' => 4,
        'BANK' => 5,
        'OTHER' => 6,
        'CHEQUE' => 7,
        'ROOM' => 8,
    ];

    private const HIDDEN_METHODS = ['VOUCHER', 'POINTS', 'LOYALTY', 'LOYALTY_POINTS'];

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
        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        $catalog = $this->paymentMethodCatalog($organizationId);

        $paymentsBase = DB::table('hospitality_check_payments as p')
            ->join('hospitality_checks as c', 'c.id', '=', 'p.check_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.received_by')
            ->leftJoin('hospitality_outlets as o', 'o.id', '=', 'c.outlet_id')
            ->where('c.organization_id', $organizationId)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->where('p.amount', '>', 0.0001);

        if ($fromDate) {
            $paymentsBase->whereDate(DB::raw('COALESCE(c.closed_at, p.created_at, c.updated_at)'), '>=', $fromDate);
        }
        if ($toDate) {
            $paymentsBase->whereDate(DB::raw('COALESCE(c.closed_at, p.created_at, c.updated_at)'), '<=', $toDate);
        }
        if ($branchId) {
            $paymentsBase->where('c.branch_id', $branchId);
        }
        if ($cashierId) {
            $paymentsBase->where(function ($q) use ($cashierId) {
                $q->where('p.received_by', $cashierId)
                    ->orWhere('c.closed_by', $cashierId)
                    ->orWhere('c.opened_by', $cashierId);
            });
        }

        $methodTotals = (clone $paymentsBase)
            ->select([
                DB::raw('UPPER(TRIM(COALESCE(p.method_code, ""))) as method_code'),
                DB::raw('SUM(p.amount) as total_amount'),
                DB::raw('COUNT(DISTINCT p.check_id) as order_count'),
            ])
            ->groupBy(DB::raw('UPPER(TRIM(COALESCE(p.method_code, "")))'))
            ->get();

        $methods = [];
        foreach ($methodTotals as $row) {
            $code = $this->normalizeMethodCode((string) $row->method_code);
            if ($code === '' || in_array($code, self::HIDDEN_METHODS, true)) {
                continue;
            }
            $methods[] = [
                'method_code' => $code,
                'method_name' => $catalog[$code]['method_name'] ?? $this->methodLabel($code),
                'total_amount' => round((float) $row->total_amount, 2),
                'order_count' => (int) $row->order_count,
                'return_amount' => 0,
                'topup_amount' => 0,
            ];
        }

        usort($methods, function ($a, $b) {
            $pa = self::METHOD_PRIORITY[$a['method_code']] ?? 50;
            $pb = self::METHOD_PRIORITY[$b['method_code']] ?? 50;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp($a['method_name'], $b['method_name']);
        });

        if ($methodCode === '' && $methods !== []) {
            $methodCode = $methods[0]['method_code'];
        }

        $listQuery = (clone $paymentsBase)
            ->whereRaw('UPPER(TRIM(COALESCE(p.method_code, ""))) = ?', [$methodCode]);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $amount = SqlLikeSearch::parseAmountSearchTerm($search);
            $listQuery->where(function ($inner) use ($like, $amount) {
                $inner->where('c.check_number', 'like', $like)
                    ->orWhere('c.guest_name', 'like', $like)
                    ->orWhere('p.reference', 'like', $like)
                    ->orWhere('o.name', 'like', $like);
                if ($amount !== null) {
                    $inner->orWhereRaw('ROUND(p.amount, 2) = ?', [$amount])
                        ->orWhereRaw('ROUND(COALESCE(c.total, 0), 2) = ?', [$amount])
                        ->orWhereRaw('ROUND(COALESCE(c.amount_paid, 0), 2) = ?', [$amount]);
                }
            });
        }

        $summaryTotal = (float) (clone $listQuery)->sum('p.amount');

        $paginator = $listQuery
            ->select([
                'p.id as payment_id',
                'p.check_id',
                'p.amount',
                'p.reference',
                'p.method_code',
                'p.created_at as paid_at',
                'c.check_number',
                'c.guest_name',
                'c.total as order_total',
                'c.amount_paid',
                'c.branch_id',
                'c.status',
                'o.name as outlet_name',
                DB::raw('COALESCE(NULLIF(TRIM(u.full_name), ""), u.username) as cashier_name'),
            ])
            ->orderByDesc(DB::raw('COALESCE(c.closed_at, p.created_at)'))
            ->orderByDesc('p.id')
            ->paginate($perPage, ['*'], 'page', $page);

        $checkIds = collect($paginator->items())->pluck('check_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $tendersByCheck = $this->tendersByCheck($checkIds);

        $active = collect($methods)->firstWhere('method_code', $methodCode) ?? [
            'method_code' => $methodCode,
            'method_name' => $catalog[$methodCode]['method_name'] ?? $this->methodLabel($methodCode),
            'total_amount' => round($summaryTotal, 2),
            'order_count' => 0,
        ];

        $rows = collect($paginator->items())->map(function ($row) use ($methodCode, $tendersByCheck, $catalog, $active) {
            $checkId = (int) $row->check_id;
            $tenders = $tendersByCheck[$checkId] ?? [];
            $isMixed = count($tenders) > 1;
            $otherMethods = [];
            foreach ($tenders as $code => $amount) {
                $code = $this->normalizeMethodCode((string) $code);
                if ($code === $methodCode || in_array($code, self::HIDDEN_METHODS, true)) {
                    continue;
                }
                $otherMethods[] = [
                    'method_code' => $code,
                    'method_name' => $catalog[$code]['method_name'] ?? $this->methodLabel($code),
                    'amount' => round((float) $amount, 2),
                ];
            }

            $orderLabel = $row->check_number ?: ('Check #'.$checkId);

            return [
                'sale_id' => $checkId,
                'payment_id' => (int) $row->payment_id,
                'order_num' => $orderLabel,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'channel' => 'hotel_pos',
                'customer_num' => null,
                'customer_name' => $row->guest_name ?: ($row->outlet_name ?: 'Walk-in'),
                'amount' => round((float) $row->amount, 2),
                'return_amount' => 0,
                'topup_amount' => 0,
                'amount_paid' => round((float) ($row->amount_paid ?? 0), 2),
                'order_total' => round((float) ($row->order_total ?? 0), 2),
                'paid_at' => $row->paid_at,
                'cashier_name' => $row->cashier_name,
                'till_number' => null,
                'till_name' => $row->outlet_name,
                'session_status' => $row->status,
                'payment_method' => $active['method_name'] ?? $this->methodLabel($methodCode),
                'method_code' => $methodCode,
                'reference' => $row->reference,
                'is_mixed' => $isMixed,
                'other_methods' => $otherMethods,
            ];
        })->values()->all();

        return [
            'methods' => $methods,
            'method_code' => $methodCode,
            'summary' => [
                'method_code' => $methodCode,
                'method_name' => $active['method_name'] ?? $this->methodLabel($methodCode),
                'total_amount' => round($summaryTotal, 2),
                'order_count' => (int) ($active['order_count'] ?? count($rows)),
                'return_amount' => 0,
                'topup_amount' => 0,
            ],
            'data' => $rows,
            'sessions' => [],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /** @param  list<int>  $checkIds
     * @return array<int, array<string, float>>
     */
    protected function tendersByCheck(array $checkIds): array
    {
        if ($checkIds === []) {
            return [];
        }

        $rows = DB::table('hospitality_check_payments')
            ->whereIn('check_id', $checkIds)
            ->where('amount', '>', 0.0001)
            ->select([
                'check_id',
                DB::raw('UPPER(TRIM(COALESCE(method_code, ""))) as method_code'),
                DB::raw('SUM(amount) as total'),
            ])
            ->groupBy('check_id', DB::raw('UPPER(TRIM(COALESCE(method_code, "")))'))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $code = $this->normalizeMethodCode((string) $row->method_code);
            if ($code === '' || in_array($code, self::HIDDEN_METHODS, true)) {
                continue;
            }
            $out[(int) $row->check_id][$code] = round((float) $row->total, 2);
        }

        return $out;
    }

    /** @return array<string, array{method_name: string}> */
    protected function paymentMethodCatalog(int $organizationId): array
    {
        $rows = DB::table('payment_methods')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get(['method_code', 'method_name']);

        $catalog = [];
        foreach ($rows as $row) {
            $code = $this->normalizeMethodCode((string) $row->method_code);
            if ($code === '') {
                continue;
            }
            $catalog[$code] = ['method_name' => (string) $row->method_name];
        }

        foreach (['CASH' => 'Cash', 'MPESA' => 'M-Pesa', 'ROOM' => 'Room charge'] as $code => $name) {
            $catalog[$code] ??= ['method_name' => $name];
        }

        return $catalog;
    }

    protected function methodLabel(string $code): string
    {
        return match ($code) {
            'CASH' => 'Cash',
            'MPESA' => 'M-Pesa',
            'EQUITY' => 'Equity',
            'KCB' => 'KCB',
            'CARD' => 'Card',
            'BANK' => 'Bank',
            'OTHER' => 'Other',
            'CHEQUE' => 'Cheque',
            'ROOM' => 'Room charge',
            default => $code !== '' ? $code : 'Payment',
        };
    }

    protected function normalizeMethodCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (in_array($code, ['M-PESA', 'M_PESA'], true)) {
            return 'MPESA';
        }
        if ($code === 'CHECK') {
            return 'CHEQUE';
        }

        return $code;
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
