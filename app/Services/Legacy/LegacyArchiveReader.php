<?php

namespace App\Services\Legacy;

use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyArchiveReader
{
    public function __construct(
        protected OrganizationLegacyArchiveService $settings,
        protected LegacyArchiveConnectionManager $connections,
    ) {}

    public function isEnabled(Organization $org): bool
    {
        return $this->settings->isEnabled($org);
    }

    public function isAvailable(Organization $org): bool
    {
        return $this->connections->isReachable($org);
    }

    public function cutoverDate(Organization $org): ?Carbon
    {
        $value = $this->settings->forOrganization($org)['cutover_date'] ?? null;

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Organization $org): array
    {
        $configured = $this->settings->isConfigured($org);
        $available = $configured && $this->isAvailable($org);
        $config = $this->settings->maskForClient($this->settings->forOrganization($org));

        return [
            'organization_id' => $org->id,
            'enabled' => (bool) $config['enabled'],
            'configured' => $configured,
            'available' => $available,
            'label' => $config['label'],
            'database' => $config['database'],
            'host' => $config['host'],
            'cutover_date' => $config['cutover_date'] ?: null,
            'read_only' => true,
            'materialize_on_demand' => true,
            'master_data_in_centrix' => true,
            'scope' => 'sales_only',
            'counts' => $available ? $this->salesCounts($org) : null,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function salesCounts(Organization $org): array
    {
        $legacy = DB::connection($this->connection($org));

        return [
            'sales_pos' => (int) $legacy->table(LightStoresLegacySchema::POS_MASTERS)->count(),
            'sales_mobile' => (int) $legacy->table(LightStoresLegacySchema::ROUTE_MASTERS)->whereNull('DLT_ON')->count(),
            'sales_debtor' => (int) $legacy->table(LightStoresLegacySchema::DEBTOR_MASTERS)->whereNull('dlt_on')->count(),
        ];
    }

    /**
     * @return array{transactions: int, order_total: float, total_vat: float, by_channel: array<string, array{transactions: int, order_total: float}>}
     */
    public function salesSummary(Organization $org, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertAvailable($org);

        $legacy = DB::connection($this->connection($org));

        $pos = $legacy->table(LightStoresLegacySchema::POS_MASTERS.' as sm')
            ->join(LightStoresLegacySchema::POS_LINES.' as sp', 'sp.order_num_ref', '=', 'sm.order_num');
        $this->applyDateFilter($pos, 'sm.create_time', $from, $to);
        $posRow = (clone $pos)->selectRaw('COUNT(DISTINCT sm.order_num) as transactions, COALESCE(SUM(sp.amount), 0) as order_total, COALESCE(SUM(sp.product_vat), 0) as total_vat')->first();

        $debtor = $legacy->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->whereNull('dm.dlt_on')
            ->join(LightStoresLegacySchema::DEBTOR_LINES.' as dp', 'dp.order_num', '=', 'dm.order_num');
        $this->applyDateFilter($debtor, 'dm.create_time', $from, $to);
        $debtorRow = (clone $debtor)->selectRaw('COUNT(DISTINCT dm.order_num) as transactions, COALESCE(SUM(dp.amount), 0) as order_total, COALESCE(SUM(dp.product_vat), 0) as total_vat')->first();

        $mobile = $legacy->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
            ->whereNull('rm.DLT_ON')
            ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', 'rod.order_no', '=', 'rm.order_num');
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
    public function listSales(Organization $org, array $filters = []): array
    {
        $this->assertAvailable($org);

        $channel = $filters['channel'] ?? null;
        $from = isset($filters['from_date']) ? Carbon::parse($filters['from_date'])->startOfDay() : null;
        $to = isset($filters['to_date']) ? Carbon::parse($filters['to_date'])->endOfDay() : null;
        $q = trim((string) ($filters['q'] ?? ''));
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 200);

        if (! in_array($channel, ['pos', 'mobile', 'debtor'], true)) {
            throw new RuntimeException('Specify channel=pos, mobile, or debtor when listing legacy archive sales.');
        }

        $connection = $this->connection($org);
        $query = match ($channel) {
            'pos' => $this->posSalesQuery($connection, $from, $to, $q),
            'mobile' => $this->mobileSalesQuery($connection, $from, $to, $q),
            'debtor' => $this->debtorSalesQuery($connection, $from, $to, $q),
        };

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())
                ->map(fn ($row) => $this->presentSaleRow($org, $channel, $row))
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
    public function mergeSummaryForReports(Organization $org, array $liveSummary, ?Carbon $from, ?Carbon $to): ?array
    {
        if (! $this->shouldMergeForRange($org, $from, $to)) {
            return null;
        }

        $archive = $this->salesSummary($org, $from, $to);

        return [
            'archive' => $archive,
            'combined' => [
                'transactions' => (int) ($liveSummary['transactions'] ?? 0) + $archive['transactions'],
                'order_total' => round((float) ($liveSummary['order_total'] ?? 0) + $archive['order_total'], 2),
                'total_vat' => round((float) ($liveSummary['total_vat'] ?? 0) + $archive['total_vat'], 2),
            ],
        ];
    }

    public function shouldMergeForRange(Organization $org, ?Carbon $from, ?Carbon $to): bool
    {
        if (! $this->isAvailable($org)) {
            return false;
        }

        $cutover = $this->cutoverDate($org);
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

    public function connection(Organization $org): string
    {
        return $this->connections->configureForOrganization($org);
    }

    protected function assertAvailable(Organization $org): void
    {
        if (! $this->isAvailable($org)) {
            throw new RuntimeException('Legacy archive is not enabled or the LightStores database is not reachable for this organization.');
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

    protected function posSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q)
    {
        $lineTotals = LightStoresLegacySchema::posLineTotalsSubquery($connection);

        $query = DB::connection($connection)
            ->table(LightStoresLegacySchema::POS_MASTERS.' as sm')
            ->leftJoin(LightStoresLegacySchema::POS_WALK_IN.' as sc', 'sc.order_no', '=', 'sm.order_num')
            ->leftJoinSub($lineTotals, 'line_totals', 'line_totals.order_num', '=', 'sm.order_num')
            ->selectRaw('sm.*, sc.customer_name as walk_in_name, COALESCE(line_totals.order_total, 0) as order_total, COALESCE(line_totals.total_vat, 0) as total_vat');

        $this->applyDateFilter($query, 'sm.create_time', $from, $to);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('sm.order_num', 'like', "%{$q}%")
                    ->orWhere('sc.customer_name', 'like', "%{$q}%");
            });
        }

        return $query->orderByDesc('sm.create_time')->orderByDesc('sm.order_num');
    }

    protected function mobileSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q)
    {
        $lineTotals = LightStoresLegacySchema::routeLineTotalsSubquery($connection);

        $query = DB::connection($connection)
            ->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
            ->leftJoin(LightStoresLegacySchema::CUSTOMERS.' as c', 'c.customer_num', '=', 'rm.customer_num')
            ->leftJoinSub($lineTotals, 'line_totals', 'line_totals.order_num', '=', 'rm.order_num')
            ->whereNull('rm.DLT_ON')
            ->selectRaw('rm.*, c.customer_name, COALESCE(line_totals.order_total, 0) as order_total, COALESCE(line_totals.total_vat, 0) as total_vat');

        $this->applyDateFilter($query, 'rm.create_time', $from, $to);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('rm.order_num', 'like', "%{$q}%")
                    ->orWhere('c.customer_name', 'like', "%{$q}%");
            });
        }

        return $query->orderByDesc('rm.create_time')->orderByDesc('rm.order_num');
    }

    protected function debtorSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q)
    {
        $lineTotals = LightStoresLegacySchema::debtorLineTotalsSubquery($connection);

        $query = DB::connection($connection)
            ->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->leftJoin(LightStoresLegacySchema::CUSTOMERS.' as c', 'c.customer_num', '=', 'dm.customer_num')
            ->leftJoinSub($lineTotals, 'line_totals', 'line_totals.order_num', '=', 'dm.order_num')
            ->whereNull('dm.dlt_on')
            ->selectRaw('dm.*, c.customer_name, COALESCE(line_totals.order_total, 0) as order_total, COALESCE(line_totals.total_vat, 0) as total_vat');

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
    protected function presentSaleRow(Organization $org, string $channel, object $row): array
    {
        $legacyOrderNum = (int) ($row->order_num ?? 0);
        $labelPrefix = match ($channel) {
            'mobile' => 'M',
            'debtor' => 'D',
            default => 'R',
        };

        $materializedSaleId = $this->materializedSaleId($org, $channel, $legacyOrderNum);
        $orderTotal = round((float) ($row->order_total ?? 0), 2);
        $totalVat = round((float) ($row->total_vat ?? 0), 2);

        $base = [
            'archive_source' => 'lightstores',
            'archive_channel' => $channel,
            'legacy_order_num' => $legacyOrderNum,
            'legacy_order_label' => $labelPrefix.$legacyOrderNum,
            'sale_date' => $row->create_time ?? null,
            'order_total' => $orderTotal,
            'total_vat' => $totalVat,
            'read_only' => $materializedSaleId === null,
            'materialized_sale_id' => $materializedSaleId,
            'can_materialize' => true,
            'can_create_return' => $materializedSaleId !== null,
        ];

        return match ($channel) {
            'pos' => array_merge($base, [
                'channel' => 'pos',
                'customer_name' => $row->walk_in_name ?? null,
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
                'channel' => 'debtor',
                'customer_num' => (int) ($row->customer_num ?? 0),
                'customer_name' => $row->customer_name ?? null,
                'payment_status' => (int) ($row->payment_status ?? 1),
                'cashier_legacy_id' => (int) ($row->user_id ?? 0),
                'is_credit_sale' => true,
            ]),
            default => $base,
        };
    }

    protected function materializedSaleId(Organization $org, string $channel, int $legacyOrderNum): ?int
    {
        $id = DB::table('sales')
            ->where('organization_id', $org->id)
            ->where('fulfillment_meta->legacy_import', true)
            ->where('fulfillment_meta->legacy_order_num', $legacyOrderNum)
            ->whereIn('fulfillment_meta->legacy_source', LightStoresLegacySchema::legacySourcesForChannel($channel))
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function saleDetail(Organization $org, string $channel, int $legacyOrderNum): array
    {
        $this->assertAvailable($org);

        if (! in_array($channel, ['pos', 'mobile', 'debtor'], true)) {
            throw new RuntimeException('Channel must be pos, mobile, or debtor.');
        }

        $connection = $this->connection($org);
        $row = match ($channel) {
            'pos' => $this->posSalesQuery($connection, null, null, (string) $legacyOrderNum)
                ->where('sm.order_num', $legacyOrderNum)->first(),
            'mobile' => $this->mobileSalesQuery($connection, null, null, (string) $legacyOrderNum)
                ->where('rm.order_num', $legacyOrderNum)->first(),
            'debtor' => $this->debtorSalesQuery($connection, null, null, (string) $legacyOrderNum)
                ->where('dm.order_num', $legacyOrderNum)->first(),
        };

        if (! $row) {
            throw new RuntimeException("Legacy {$channel} sale #{$legacyOrderNum} was not found.");
        }

        $header = $this->presentSaleRow($org, $channel, $row);
        $header['lines'] = $this->saleLines($connection, $channel, $legacyOrderNum);

        return $header;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dailySalesRows(Organization $org, ?Carbon $from, ?Carbon $to): array
    {
        $this->assertAvailable($org);
        $legacy = DB::connection($this->connection($org));
        $rows = [];

        $pos = $legacy->table(LightStoresLegacySchema::POS_MASTERS.' as sm')
            ->join(LightStoresLegacySchema::POS_LINES.' as sp', 'sp.order_num_ref', '=', 'sm.order_num');
        $this->applyDateFilter($pos, 'sm.create_time', $from, $to);
        foreach ($pos->selectRaw('DATE(sm.create_time) as sale_day, COUNT(DISTINCT sm.order_num) as orders, COALESCE(SUM(sp.amount), 0) as gross, COALESCE(SUM(sp.product_vat), 0) as vat')->groupByRaw('DATE(sm.create_time)')->orderBy('sale_day')->get() as $aggregate) {
            $rows[] = $this->presentDailySalesAggregate($aggregate, 'pos');
        }

        $debtor = $legacy->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->whereNull('dm.dlt_on')
            ->join(LightStoresLegacySchema::DEBTOR_LINES.' as dp', 'dp.order_num', '=', 'dm.order_num');
        $this->applyDateFilter($debtor, 'dm.create_time', $from, $to);
        foreach ($debtor->selectRaw('DATE(dm.create_time) as sale_day, COUNT(DISTINCT dm.order_num) as orders, COALESCE(SUM(dp.amount), 0) as gross, COALESCE(SUM(dp.product_vat), 0) as vat')->groupByRaw('DATE(dm.create_time)')->orderBy('sale_day')->get() as $aggregate) {
            $rows[] = $this->presentDailySalesAggregate($aggregate, 'credit');
        }

        $mobile = $legacy->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
            ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', 'rod.order_no', '=', 'rm.order_num')
            ->whereNull('rm.DLT_ON');
        $this->applyDateFilter($mobile, 'rm.create_time', $from, $to);
        foreach ($mobile->selectRaw('DATE(rm.create_time) as sale_day, COUNT(DISTINCT rm.order_num) as orders, COALESCE(SUM(rod.amount), 0) as gross, COALESCE(SUM(rod.product_vat), 0) as vat')->groupByRaw('DATE(rm.create_time)')->orderBy('sale_day')->get() as $aggregate) {
            $rows[] = $this->presentDailySalesAggregate($aggregate, 'mobile');
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDailySalesAggregate(object $aggregate, string $channel): array
    {
        $gross = round((float) $aggregate->gross, 2);
        $vat = round((float) $aggregate->vat, 2);

        return [
            'sale_day' => (string) $aggregate->sale_day,
            'branch_id' => null,
            'branch_name' => 'Legacy archive',
            'channel' => $channel,
            'orders' => (int) $aggregate->orders,
            'gross' => $gross,
            'vat' => $vat,
            'net' => round($gross - $vat, 2),
            'legacy_archive' => true,
            'archive_source' => 'lightstores',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function salesByChannelRows(Organization $org, ?Carbon $from, ?Carbon $to): array
    {
        $this->assertAvailable($org);
        $legacy = DB::connection($this->connection($org));
        $rows = [];

        $pos = $legacy->table(LightStoresLegacySchema::POS_MASTERS.' as sm')
            ->join(LightStoresLegacySchema::POS_LINES.' as sp', 'sp.order_num_ref', '=', 'sm.order_num');
        $this->applyDateFilter($pos, 'sm.create_time', $from, $to);
        $posAgg = $pos
            ->selectRaw("DATE(sm.create_time) as sale_date, COUNT(DISTINCT sm.order_num) as order_count, COALESCE(SUM(sp.amount), 0) as gross_sales, COALESCE(SUM(sp.amount), 0) as collected, COALESCE(SUM(sp.product_vat), 0) as total_vat")
            ->groupByRaw('DATE(sm.create_time)')
            ->get();

        foreach ($posAgg as $aggregate) {
            $gross = round((float) $aggregate->gross_sales, 2);
            $vat = round((float) $aggregate->total_vat, 2);
            $rows[] = [
                'sale_date' => (string) $aggregate->sale_date,
                'branch_id' => null,
                'branch_name' => 'Legacy archive',
                'channel' => 'pos',
                'payment_status' => 'paid',
                'order_count' => (int) $aggregate->order_count,
                'gross_sales' => $gross,
                'collected' => round((float) $aggregate->collected, 2),
                'total_vat' => $vat,
                'net_sales' => round($gross - $vat, 2),
                'credit_sales' => 0,
                'legacy_archive' => true,
                'archive_source' => 'lightstores',
            ];
        }

        $debtor = $legacy->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->whereNull('dm.dlt_on')
            ->join(LightStoresLegacySchema::DEBTOR_LINES.' as dp', 'dp.order_num', '=', 'dm.order_num');
        $this->applyDateFilter($debtor, 'dm.create_time', $from, $to);
        $debtorAgg = $debtor
            ->selectRaw('DATE(dm.create_time) as sale_date, COUNT(DISTINCT dm.order_num) as order_count, COALESCE(SUM(dp.amount), 0) as gross_sales, COALESCE(SUM(dp.product_vat), 0) as total_vat')
            ->groupByRaw('DATE(dm.create_time)')
            ->get();

        foreach ($debtorAgg as $aggregate) {
            $gross = round((float) $aggregate->gross_sales, 2);
            $vat = round((float) $aggregate->total_vat, 2);
            $rows[] = [
                'sale_date' => (string) $aggregate->sale_date,
                'branch_id' => null,
                'branch_name' => 'Legacy archive',
                'channel' => 'credit',
                'payment_status' => 'unpaid',
                'order_count' => (int) $aggregate->order_count,
                'gross_sales' => $gross,
                'collected' => 0,
                'total_vat' => $vat,
                'net_sales' => round($gross - $vat, 2),
                'credit_sales' => $gross,
                'legacy_archive' => true,
                'archive_source' => 'lightstores',
            ];
        }

        $mobile = $legacy->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
            ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', 'rod.order_no', '=', 'rm.order_num')
            ->whereNull('rm.DLT_ON');
        $this->applyDateFilter($mobile, 'rm.create_time', $from, $to);
        $mobileAgg = $mobile
            ->selectRaw('DATE(rm.create_time) as sale_date, COUNT(DISTINCT rm.order_num) as order_count, COALESCE(SUM(rod.amount), 0) as gross_sales, COALESCE(SUM(rod.product_vat), 0) as total_vat')
            ->groupByRaw('DATE(rm.create_time)')
            ->get();

        foreach ($mobileAgg as $aggregate) {
            $gross = round((float) $aggregate->gross_sales, 2);
            $vat = round((float) $aggregate->total_vat, 2);
            $rows[] = [
                'sale_date' => (string) $aggregate->sale_date,
                'branch_id' => null,
                'branch_name' => 'Legacy archive',
                'channel' => 'mobile',
                'payment_status' => 'paid',
                'order_count' => (int) $aggregate->order_count,
                'gross_sales' => $gross,
                'collected' => $gross,
                'total_vat' => $vat,
                'net_sales' => round($gross - $vat, 2),
                'credit_sales' => 0,
                'legacy_archive' => true,
                'archive_source' => 'lightstores',
            ];
        }

        return $rows;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginatedDailySalesRows(Organization $org, ?Carbon $from, ?Carbon $to, int $page, int $perPage): array
    {
        return $this->paginateRows($this->dailySalesRows($org, $from, $to), $page, $perPage, 'sale_day');
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginatedSalesByChannelRows(Organization $org, ?Carbon $from, ?Carbon $to, int $page, int $perPage): array
    {
        return $this->paginateRows($this->salesByChannelRows($org, $from, $to), $page, $perPage, 'sale_date');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    protected function paginateRows(array $rows, int $page, int $perPage, string $sortKey, bool $sortDesc = true): array
    {
        usort($rows, function (array $a, array $b) use ($sortKey, $sortDesc) {
            $cmp = strcmp((string) ($a[$sortKey] ?? ''), (string) ($b[$sortKey] ?? ''));

            return $sortDesc ? -$cmp : $cmp;
        });

        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $total = count($rows);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_values(array_slice($rows, $offset, $perPage)),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function saleLines(string $connection, string $channel, int $legacyOrderNum): array
    {
        $lines = match ($channel) {
            'pos' => DB::connection($connection)
                ->table(LightStoresLegacySchema::POS_LINES)
                ->where('order_num_ref', $legacyOrderNum)
                ->orderBy('id')
                ->get(),
            'mobile' => DB::connection($connection)
                ->table(LightStoresLegacySchema::ROUTE_LINES)
                ->where('order_no', $legacyOrderNum)
                ->orderBy('id')
                ->get(),
            'debtor' => DB::connection($connection)
                ->table(LightStoresLegacySchema::DEBTOR_LINES)
                ->where('order_num', $legacyOrderNum)
                ->orderBy('id')
                ->get(),
        };

        return $lines->map(function ($line) use ($channel) {
            $productCode = (string) ($line->productsales_id ?? $line->product_code ?? $line->item_code ?? '');
            $productName = $productCode
                ? (DB::table('products')->where('product_code', $productCode)->value('product_name') ?? $productCode)
                : null;

            return [
                'product_code' => $productCode,
                'product_name' => $productName,
                'quantity' => (float) ($line->quantity ?? $line->qty_ordered ?? 0),
                'uom' => $line->uom ?? null,
                'unit_price' => round((float) ($line->selling_price ?? $line->unit_price ?? 0), 2),
                'discount' => round((float) ($line->discount_given ?? 0), 2),
                'vat' => round((float) ($line->product_vat ?? 0), 2),
                'amount' => round((float) ($line->amount ?? 0), 2),
                'channel' => $channel,
            ];
        })->values()->all();
    }
}
