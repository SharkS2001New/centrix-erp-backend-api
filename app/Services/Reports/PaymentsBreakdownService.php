<?php

namespace App\Services\Reports;

use App\Services\Sales\CentrixSalesScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentsBreakdownService
{
    /** Preferred tab order: Cash, M-Pesa, then remaining methods. */
    private const METHOD_PRIORITY = [
        'CASH' => 0,
        'MPESA' => 1,
        'M-PESA' => 1,
        'M_PESA' => 1,
        'AIRTEL' => 2,
        'CARD' => 3,
        'BANK' => 4,
        'BANK_TRANSFER' => 4,
        'TRANSFER' => 4,
        'CHEQUE' => 5,
        'EQUITY' => 6,
        'KCB' => 7,
        'VOUCHER' => 8,
        'POINTS' => 9,
        'CREDIT' => 10,
        'OTHER' => 11,
    ];

    /**
     * @return array{
     *   from_date: ?string,
     *   to_date: ?string,
     *   methods: list<array<string, mixed>>,
     *   data: list<array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   current_page: int,
     *   per_page: int,
     *   total: int,
     *   last_page: int
     * }
     */
    public function build(Request $request, int $organizationId, ?int $branchId = null): array
    {
        $fromDate = $this->nullableDate($request->input('from_date'));
        $toDate = $this->nullableDate($request->input('to_date'));
        $methodCode = $this->normalizeMethodCode((string) $request->input('method_code', ''));
        $search = trim((string) $request->input('q', ''));
        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);

        $base = $this->baseQuery($organizationId, $branchId, $fromDate, $toDate);

        $methodRows = (clone $base)
            ->select([
                'pm.method_code',
                'pm.method_name',
                DB::raw('COUNT(sp.id) as payment_count'),
                DB::raw('COUNT(DISTINCT sp.sale_id) as order_count'),
                DB::raw('COALESCE(SUM(sp.amount), 0) as total_amount'),
            ])
            ->groupBy('pm.method_code', 'pm.method_name')
            ->get();

        $methods = $this->sortAndPresentMethods($methodRows);

        if ($methodCode === '' && $methods !== []) {
            $methodCode = (string) $methods[0]['method_code'];
        }

        $active = collect($methods)->firstWhere('method_code', $methodCode);
        if (! $active && $methods !== []) {
            $methodCode = (string) $methods[0]['method_code'];
            $active = $methods[0];
        }

        $paymentsQuery = clone $base;
        if ($methodCode !== '') {
            $aliases = $this->methodCodeAliases($methodCode);
            $paymentsQuery->where(function ($inner) use ($aliases) {
                foreach ($aliases as $alias) {
                    $inner->orWhereRaw('UPPER(TRIM(pm.method_code)) = ?', [$alias]);
                }
            });
        } else {
            $paymentsQuery->whereRaw('1 = 0');
        }

        if ($search !== '') {
            $paymentsQuery->where(function ($inner) use ($search) {
                $inner->where('s.order_num', 'like', "%{$search}%")
                    ->orWhere('sp.reference_number', 'like', "%{$search}%")
                    ->orWhere('s.customer_name_override', 'like', "%{$search}%")
                    ->orWhere('c.customer_name', 'like', "%{$search}%")
                    ->orWhere('s.customer_num', 'like', "%{$search}%");
            });
        }

        $summaryRaw = (clone $paymentsQuery)
            ->reorder()
            ->select([
                DB::raw('COUNT(sp.id) as payment_count'),
                DB::raw('COUNT(DISTINCT sp.sale_id) as order_count'),
                DB::raw('COALESCE(SUM(sp.amount), 0) as total_amount'),
            ])
            ->first();

        $paginator = $paymentsQuery
            ->select([
                'sp.id as payment_id',
                'sp.sale_id',
                's.order_num',
                's.branch_id',
                's.channel',
                's.customer_num',
                's.customer_name_override',
                'c.customer_name',
                'sp.amount',
                'sp.reference_number',
                'sp.paid_at',
                'pm.method_code',
                'pm.method_name',
            ])
            ->orderByDesc('sp.paid_at')
            ->orderByDesc('sp.id')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function ($row) {
            $customerName = trim((string) ($row->customer_name_override ?? ''))
                ?: trim((string) ($row->customer_name ?? ''))
                ?: null;

            return [
                'payment_id' => (int) $row->payment_id,
                'sale_id' => (int) $row->sale_id,
                'order_num' => $row->order_num !== null ? (int) $row->order_num : null,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'channel' => $row->channel,
                'customer_num' => $row->customer_num !== null ? (int) $row->customer_num : null,
                'customer_name' => $customerName,
                'amount' => round((float) $row->amount, 2),
                'reference_number' => $row->reference_number,
                'mpesa_code' => $row->reference_number,
                'paid_at' => $row->paid_at,
                'method_code' => $this->normalizeMethodCode((string) $row->method_code),
                'method_name' => $row->method_name,
            ];
        })->values()->all();

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'method_code' => $methodCode !== '' ? $methodCode : null,
            'methods' => $methods,
            'data' => $rows,
            'summary' => [
                'method_code' => $active['method_code'] ?? $methodCode,
                'method_name' => $active['method_name'] ?? null,
                'payment_count' => (int) ($summaryRaw->payment_count ?? 0),
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

    protected function baseQuery(int $organizationId, ?int $branchId, ?string $fromDate, ?string $toDate)
    {
        $q = DB::table('sale_payments as sp')
            ->join('sales as s', 's.id', '=', 'sp.sale_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->leftJoin('customers as c', function ($join) use ($organizationId) {
                $join->on('c.customer_num', '=', 's.customer_num');
                if (Schema::hasColumn('customers', 'organization_id')) {
                    $join->where('c.organization_id', '=', $organizationId);
                }
            })
            ->where('s.organization_id', $organizationId)
            ->whereIn('s.status', CentrixSalesScope::reportPipelineStatuses());

        CentrixSalesScope::excludeLegacyMaterialized($q, 's');

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
            $q->whereDate('sp.paid_at', '>=', $fromDate);
        }
        if ($toDate) {
            $q->whereDate('sp.paid_at', '<=', $toDate);
        }

        return $q;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $methodRows
     * @return list<array<string, mixed>>
     */
    protected function sortAndPresentMethods($methodRows): array
    {
        $byCode = [];
        foreach ($methodRows as $row) {
            $code = $this->normalizeMethodCode((string) ($row->method_code ?? ''));
            if ($code === '') {
                continue;
            }
            $amount = round((float) ($row->total_amount ?? 0), 2);
            if ($amount <= 0 && (int) ($row->payment_count ?? 0) <= 0) {
                continue;
            }
            if (! isset($byCode[$code])) {
                $byCode[$code] = [
                    'method_code' => $code,
                    'method_name' => $this->methodLabel($code, (string) ($row->method_name ?? '')),
                    'payment_count' => 0,
                    'order_count' => 0,
                    'total_amount' => 0.0,
                ];
            }
            $byCode[$code]['payment_count'] += (int) ($row->payment_count ?? 0);
            $byCode[$code]['order_count'] += (int) ($row->order_count ?? 0);
            $byCode[$code]['total_amount'] = round($byCode[$code]['total_amount'] + $amount, 2);
            $name = trim((string) ($row->method_name ?? ''));
            if ($name !== '') {
                $byCode[$code]['method_name'] = $this->methodLabel($code, $name);
            }
        }

        // Always surface Cash and M-Pesa tabs first for cashiers/accountants.
        foreach (['CASH' => 'Cash', 'MPESA' => 'M-Pesa'] as $code => $label) {
            if (! isset($byCode[$code])) {
                $byCode[$code] = [
                    'method_code' => $code,
                    'method_name' => $label,
                    'payment_count' => 0,
                    'order_count' => 0,
                    'total_amount' => 0.0,
                ];
            }
        }

        $methods = array_values($byCode);
        usort($methods, function (array $a, array $b) {
            $pa = self::METHOD_PRIORITY[$a['method_code']] ?? 100;
            $pb = self::METHOD_PRIORITY[$b['method_code']] ?? 100;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp($a['method_name'], $b['method_name']);
        });

        return $methods;
    }

    protected function normalizeMethodCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'M_PESA', 'MPESA' => 'MPESA',
            'BANK_TRANSFER', 'TRANSFER' => 'BANK',
            default => $normalized,
        };
    }

    /** @return list<string> */
    protected function methodCodeAliases(string $code): array
    {
        return match ($code) {
            'MPESA' => ['MPESA', 'M_PESA', 'M-PESA'],
            'BANK' => ['BANK', 'BANK_TRANSFER', 'TRANSFER'],
            default => [$code],
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
            'CARD' => 'Card',
            'BANK' => 'Bank Transfer',
            'CHEQUE' => 'Cheque',
            'VOUCHER' => 'Voucher',
            'POINTS' => 'Loyalty Points',
            'EQUITY' => 'Equity',
            'KCB' => 'KCB',
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
