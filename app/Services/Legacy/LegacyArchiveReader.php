<?php

namespace App\Services\Legacy;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyArchiveReader
{
    protected string $connection;

    public function __construct()
    {
        $this->connection = (string) config('legacy_archive.connection', 'legacy');
    }

    public function isEnabled(): bool
    {
        return (bool) config('legacy_archive.enabled', false);
    }

    public function isAvailable(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            DB::connection($this->connection)->select('SELECT 1');

            return DB::connection($this->connection)->getSchemaBuilder()->hasTable('org_info');
        } catch (\Throwable) {
            return false;
        }
    }

    public function cutoverDate(): ?Carbon
    {
        $value = config('legacy_archive.cutover_date');

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $available = $this->isAvailable();

        return [
            'enabled' => $this->isEnabled(),
            'available' => $available,
            'label' => (string) config('legacy_archive.label', 'LightStores archive'),
            'database' => config('database.connections.'.$this->connection.'.database'),
            'cutover_date' => $this->cutoverDate()?->toDateString(),
    'read_only' => true,
    'materialize_on_demand' => true,
    'counts' => $available ? $this->tableCounts() : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function tableCounts(): array
    {
        $legacy = DB::connection($this->connection);

        return [
            'products' => (int) $legacy->table('product')->count(),
            'customers' => (int) $legacy->table('customer')->whereNull('dlt_on')->count(),
            'sales_pos' => (int) $legacy->table('sale_masters')->count(),
            'sales_mobile' => (int) $legacy->table('route_master')->whereNull('DLT_ON')->count(),
            'sales_debtor' => (int) $legacy->table('debtor_masters')->whereNull('dlt_on')->count(),
        ];
    }

    /**
     * @return array{transactions: int, order_total: float, total_vat: float, by_channel: array<string, array{transactions: int, order_total: float}>}
     */
    public function salesSummary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertAvailable();

        $legacy = DB::connection($this->connection);

        $pos = $legacy->table('sale_masters');
        $this->applyDateFilter($pos, 'create_time', $from, $to);
        $posRow = (clone $pos)->selectRaw('COUNT(*) as transactions, COALESCE(SUM(order_total), 0) as order_total, COALESCE(SUM(total_vat), 0) as total_vat')->first();

        $debtor = $legacy->table('debtor_masters')->whereNull('dlt_on');
        $this->applyDateFilter($debtor, 'create_time', $from, $to);
        $debtorRow = (clone $debtor)->selectRaw('COUNT(*) as transactions, COALESCE(SUM(order_total), 0) as order_total, COALESCE(SUM(total_vat), 0) as total_vat')->first();

        $mobile = $legacy->table('route_master as rm')
            ->whereNull('rm.DLT_ON')
            ->join('route_order_details as rod', 'rod.order_no', '=', 'rm.order_num');
        $this->applyDateFilter($mobile, 'rm.create_time', $from, $to);
        $mobileRow = (clone $mobile)->selectRaw('COUNT(DISTINCT rm.order_num) as transactions, COALESCE(SUM(rod.amount), 0) as order_total, COALESCE(SUM(rod.product_vat), 0) as total_vat')->first();

        $byChannel = [
            'pos' => [
                'transactions' => (int) ($posRow->transactions ?? 0),
                'order_total' => round((float) ($posRow->order_total ?? 0), 2),
            ],
            'mobile' => [
                'transactions' => (int) ($mobileRow->transactions ?? 0),
                'order_total' => round((float) ($mobileRow->order_total ?? 0), 2),
            ],
            'debtor' => [
                'transactions' => (int) ($debtorRow->transactions ?? 0),
                'order_total' => round((float) ($debtorRow->order_total ?? 0), 2),
            ],
        ];

        return [
            'transactions' => array_sum(array_column($byChannel, 'transactions')),
            'order_total' => round(array_sum(array_column($byChannel, 'order_total')), 2),
            'total_vat' => round(
                (float) ($posRow->total_vat ?? 0)
                + (float) ($debtorRow->total_vat ?? 0)
                + (float) ($mobileRow->total_vat ?? 0),
                2,
            ),
            'by_channel' => $byChannel,
        ];
    }

    /**
     * @param  array{channel?: string|null, from_date?: string|null, to_date?: string|null, q?: string|null, page?: int, per_page?: int}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listSales(array $filters = [], ?int $organizationId = null): array
    {
        $this->assertAvailable();

        $channel = $filters['channel'] ?? null;
        $from = isset($filters['from_date']) ? Carbon::parse($filters['from_date'])->startOfDay() : null;
        $to = isset($filters['to_date']) ? Carbon::parse($filters['to_date'])->endOfDay() : null;
        $q = trim((string) ($filters['q'] ?? ''));
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);

        if (! in_array($channel, ['pos', 'mobile', 'debtor'], true)) {
            throw new RuntimeException('Specify channel=pos, mobile, or debtor when listing legacy archive sales.');
        }

        $query = match ($channel) {
            'pos' => $this->posSalesQuery($from, $to, $q),
            'mobile' => $this->mobileSalesQuery($from, $to, $q),
            'debtor' => $this->debtorSalesQuery($from, $to, $q),
        };

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())
                ->map(fn ($row) => $this->presentSaleRow($channel, $row, $organizationId))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'channel' => $channel,
                'archive_source' => 'lightstores',
            ],
        ];
    }

    /**
     * @return array{archive: array<string, mixed>, combined: array<string, mixed>}|null
     */
    public function mergeSummaryForReports(array $liveSummary, ?Carbon $from, ?Carbon $to): ?array
    {
        if (! $this->shouldMergeForRange($from, $to)) {
            return null;
        }

        $archive = $this->salesSummary($from, $to);

        return [
            'archive' => $archive,
            'combined' => [
                'transactions' => (int) ($liveSummary['transactions'] ?? 0) + $archive['transactions'],
                'order_total' => round((float) ($liveSummary['order_total'] ?? 0) + $archive['order_total'], 2),
                'total_vat' => round((float) ($liveSummary['total_vat'] ?? 0) + $archive['total_vat'], 2),
            ],
        ];
    }

    public function shouldMergeForRange(?Carbon $from, ?Carbon $to): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $cutover = $this->cutoverDate();
        if (! $cutover) {
            return true;
        }

        if ($from && $from->gt($cutover)) {
            return false;
        }

        if ($to && $to->gt($cutover)) {
            return $from !== null || $to->lte($cutover->copy()->endOfDay());
        }

        return true;
    }

    protected function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('Legacy archive is not enabled or the LightStores database is not reachable.');
        }
    }

    protected function applyDateFilter($query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from) {
            $query->whereDate($column, '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate($column, '<=', $to->toDateString());
        }
    }

    protected function posSalesQuery(?Carbon $from, ?Carbon $to, string $q)
    {
        $query = DB::connection($this->connection)
            ->table('sale_masters as sm')
            ->leftJoin('sale_customers as sc', 'sc.order_no', '=', 'sm.order_num')
            ->selectRaw('sm.*, sc.customer_name as walk_in_name');

        $this->applyDateFilter($query, 'sm.create_time', $from, $to);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('sm.order_num', 'like', "%{$q}%")
                    ->orWhere('sc.customer_name', 'like', "%{$q}%");
            });
        }

        return $query->orderByDesc('sm.create_time')->orderByDesc('sm.order_num');
    }

    protected function mobileSalesQuery(?Carbon $from, ?Carbon $to, string $q)
    {
        $query = DB::connection($this->connection)
            ->table('route_master as rm')
            ->leftJoin('customer as c', 'c.customer_num', '=', 'rm.customer_num')
            ->whereNull('rm.DLT_ON')
            ->selectRaw('rm.*, c.customer_name');

        $this->applyDateFilter($query, 'rm.create_time', $from, $to);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('rm.order_num', 'like', "%{$q}%")
                    ->orWhere('c.customer_name', 'like', "%{$q}%");
            });
        }

        return $query->orderByDesc('rm.create_time')->orderByDesc('rm.order_num');
    }

    protected function debtorSalesQuery(?Carbon $from, ?Carbon $to, string $q)
    {
        $query = DB::connection($this->connection)
            ->table('debtor_masters as dm')
            ->leftJoin('customer as c', 'c.customer_num', '=', 'dm.customer_num')
            ->whereNull('dm.dlt_on')
            ->selectRaw('dm.*, c.customer_name');

        $this->applyDateFilter($query, 'dm.create_time', $from, $to);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('dm.order_num', 'like', "%{$q}%")
                    ->orWhere('c.customer_name', 'like', "%{$q}%");
            });
        }

        return $query->orderByDesc('dm.create_time')->orderByDesc('dm.order_num');
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSaleRow(string $channel, object $row, ?int $organizationId = null): array
    {
        $legacyOrderNum = (int) ($row->order_num ?? 0);
        $labelPrefix = match ($channel) {
            'mobile' => 'M',
            'debtor' => 'D',
            default => 'R',
        };

        $materializedSaleId = $this->materializedSaleId($channel, $legacyOrderNum, $organizationId);

        $base = [
            'archive_source' => 'lightstores',
            'archive_channel' => $channel,
            'legacy_order_num' => $legacyOrderNum,
            'legacy_order_label' => $labelPrefix.$legacyOrderNum,
            'sale_date' => $row->create_time ?? null,
            'read_only' => $materializedSaleId === null,
            'materialized_sale_id' => $materializedSaleId,
            'can_materialize' => true,
            'can_create_return' => $materializedSaleId !== null,
        ];

        return match ($channel) {
            'pos' => array_merge($base, [
                'channel' => 'pos',
                'customer_name' => $row->walk_in_name ?? null,
                'order_total' => round((float) $row->order_total, 2),
                'total_vat' => round((float) $row->total_vat, 2),
                'cash' => (int) ($row->cash ?? 0),
                'mpesa_amount' => (int) ($row->mpesa_amount ?? 0),
                'cashier_legacy_id' => (int) ($row->userid_sales ?? 0),
                'payment_method' => $row->payment_method ?? null,
            ]),
            'mobile' => array_merge($base, [
                'channel' => 'mobile',
                'customer_num' => (int) ($row->customer_num ?? 0),
                'customer_name' => $row->customer_name ?? null,
                'order_status' => (int) ($row->order_status ?? 0),
                'cashier_legacy_id' => (int) ($row->user_id ?? 0),
                'required_date' => $row->required_date ?? null,
                'delivery_date' => $row->delivery_date ?? null,
            ]),
            'debtor' => array_merge($base, [
                'channel' => 'mobile',
                'customer_num' => (int) ($row->customer_num ?? 0),
                'customer_name' => $row->customer_name ?? null,
                'order_total' => round((float) $row->order_total, 2),
                'total_vat' => round((float) $row->total_vat, 2),
                'payment_status' => (int) ($row->payment_status ?? 1),
                'cashier_legacy_id' => (int) ($row->user_id ?? 0),
                'is_credit_sale' => true,
            ]),
            default => $base,
        };
    }

    protected function materializedSaleId(string $channel, int $legacyOrderNum, ?int $organizationId): ?int
    {
        $legacySource = match ($channel) {
            'mobile' => 'route_master',
            'debtor' => 'debtor_masters',
            default => 'sale_masters',
        };

        $query = DB::table('sales')
            ->where('fulfillment_meta->legacy_import', true)
            ->where('fulfillment_meta->legacy_order_num', $legacyOrderNum)
            ->where('fulfillment_meta->legacy_source', $legacySource);

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $id = $query->value('id');

        return $id ? (int) $id : null;
    }
}
