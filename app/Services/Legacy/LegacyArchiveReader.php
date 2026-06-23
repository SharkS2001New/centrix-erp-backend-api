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
            ->join(LightStoresLegacySchema::POS_LINES.' as sp', function ($join) {
                $join->on('sp.order_num_ref', '=', 'sm.order_num')
                    ->on('sp.create_time', '=', 'sm.create_time');
            });
        $this->applyLegacyDateRange($pos, 'sm.create_time', $from, $to);
        $posRow = (clone $pos)->selectRaw('COUNT(DISTINCT CONCAT(sm.order_num, \'|\', sm.create_time)) as transactions, COALESCE(SUM(sp.amount), 0) as order_total, COALESCE(SUM(sp.product_vat), 0) as total_vat')->first();

        $debtor = $legacy->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->whereNull('dm.dlt_on')
            ->join(LightStoresLegacySchema::DEBTOR_LINES.' as dp', 'dp.order_num', '=', 'dm.order_num');
        $this->applyLegacyDateRange($debtor, 'dm.create_time', $from, $to);
        $debtorRow = (clone $debtor)->selectRaw('COUNT(DISTINCT dm.order_num) as transactions, COALESCE(SUM(dp.amount), 0) as order_total, COALESCE(SUM(dp.product_vat), 0) as total_vat')->first();

        $mobile = $legacy->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
            ->whereNull('rm.DLT_ON')
            ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', function ($join) {
                $join->on('rod.order_no', '=', 'rm.order_num')
                    ->on('rod.create_time', '=', 'rm.create_time');
            });
        $this->applyLegacyDateRange($mobile, 'rm.create_time', $from, $to);
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

        if (! in_array($channel, ['pos', 'mobile', 'debtor', 'all'], true)) {
            throw new RuntimeException('Specify channel=pos, mobile, debtor, or all when listing legacy archive sales.');
        }

        $connection = $this->connection($org);

        if ($channel === 'all') {
            return $this->listAllChannelSales($org, $connection, $from, $to, $q, $page, $perPage);
        }

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

    protected function applyLegacyDateRange($query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from) {
            $query->whereDate($column, '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate($column, '<', $to->copy()->addDay()->toDateString());
        }
    }

    protected function applyLegacyDateRangeOnDateColumn($query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from) {
            $query->where($column, '>=', $from->toDateString());
        }
        if ($to) {
            $query->where($column, '<', $to->copy()->addDay()->toDateString());
        }
    }

    protected function normalizeSaleDate(mixed $createTime): string
    {
        if (! $createTime) {
            throw new RuntimeException('Legacy sale date is required.');
        }

        return Carbon::parse($createTime)->toDateString();
    }

    protected function channelLabelPrefix(string $channel): string
    {
        return match ($channel) {
            'mobile' => 'M',
            'debtor' => 'D',
            default => 'P',
        };
    }

    /**
     * Display prefixes (P/M/D) are cosmetic — API filters always use the numeric legacy order #.
     */
    protected function parseLegacyOrderNum(int|string $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        $normalized = preg_replace('/^[PMD]/i', '', trim($value)) ?? trim($value);

        return (int) $normalized;
    }

    protected function orderNumSearchTerm(string $q): string
    {
        $trimmed = trim($q);

        return preg_replace('/^[PMD]/i', '', $trimmed) ?: $trimmed;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    protected function listAllChannelSales(
        Organization $org,
        string $connection,
        ?Carbon $from,
        ?Carbon $to,
        string $q,
        int $page,
        int $perPage,
    ): array {
        $union = $this->posSalesQuery($connection, $from, $to, $q, null, null, true)
            ->unionAll($this->mobileSalesQuery($connection, $from, $to, $q, null, null, true))
            ->unionAll($this->debtorSalesQuery($connection, $from, $to, $q, null, null, true));

        $total = (int) DB::connection($connection)
            ->query()
            ->fromSub($union, 'legacy_sales')
            ->count();

        $rows = DB::connection($connection)
            ->query()
            ->fromSub($union, 'legacy_sales')
            ->orderByDesc('create_time')
            ->orderByDesc('order_num')
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => collect($rows)
                ->map(fn ($row) => $this->presentSaleRow($org, (string) $row->archive_channel, $row))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'channel' => 'all',
                'archive_source' => 'lightstores',
            ],
        ];
    }

    protected function unionSelectColumns(string $channel): string
    {
        return match ($channel) {
            'mobile' => "
                'mobile' as archive_channel,
                rm.order_num as order_num,
                rm.create_time as create_time,
                c.customer_name as customer_name,
                COALESCE(line_totals.order_total, 0) as order_total,
                COALESCE(line_totals.total_vat, 0) as total_vat,
                u.username as created_by,
                rm.user_id as cashier_legacy_id,
                NULL as payment_method,
                NULL as cash,
                NULL as mpesa_amount,
                rm.customer_num as customer_num,
                rm.order_status as order_status,
                rm.required_date as required_date,
                rm.delivery_date as delivery_date,
                NULL as payment_status,
                NULL as walk_in_name
            ",
            'debtor' => "
                'debtor' as archive_channel,
                dm.order_num as order_num,
                dm.create_time as create_time,
                c.customer_name as customer_name,
                COALESCE(line_totals.order_total, 0) as order_total,
                COALESCE(line_totals.total_vat, 0) as total_vat,
                u.username as created_by,
                dm.user_id as cashier_legacy_id,
                NULL as payment_method,
                NULL as cash,
                NULL as mpesa_amount,
                dm.customer_num as customer_num,
                NULL as order_status,
                NULL as required_date,
                NULL as delivery_date,
                dm.payment_status as payment_status,
                NULL as walk_in_name
            ",
            default => "
                'pos' as archive_channel,
                sm.order_num as order_num,
                sm.create_time as create_time,
                sc_ranked.customer_name as customer_name,
                COALESCE(line_totals.order_total, 0) as order_total,
                COALESCE(line_totals.total_vat, 0) as total_vat,
                u.username as created_by,
                sm.userid_sales as cashier_legacy_id,
                sm.payment_method as payment_method,
                sm.cash as cash,
                sm.mpesa_amount as mpesa_amount,
                NULL as customer_num,
                NULL as order_status,
                NULL as required_date,
                NULL as delivery_date,
                NULL as payment_status,
                sc_ranked.customer_name as walk_in_name
            ",
        };
    }

    protected function posSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q, ?int $orderNum = null, ?string $saleDate = null, bool $forUnion = false)
    {
        $lineTotals = ($orderNum !== null && $saleDate !== null)
            ? LightStoresLegacySchema::posOrderLineTotalsSubquery($connection, $orderNum, $saleDate)
            : LightStoresLegacySchema::posListLineTotalsSubquery($connection);
        $walkIn = LightStoresLegacySchema::posListWalkInSubquery($connection);
        $users = LightStoresLegacySchema::USERS;

        $query = DB::connection($connection)
            ->table(LightStoresLegacySchema::POS_MASTERS.' as sm')
            ->leftJoinSub($walkIn, 'sc_ranked', 'sc_ranked.order_no', '=', 'sm.order_num')
            ->leftJoinSub($lineTotals, 'line_totals', function ($join) use ($orderNum, $saleDate) {
                $join->on('line_totals.order_num', '=', 'sm.order_num');
                if ($orderNum === null || $saleDate === null) {
                    $join->on('line_totals.sale_date', '=', 'sm.create_time');
                }
            })
            ->leftJoin("{$users} as u", 'u.id', '=', 'sm.userid_sales')
            ->selectRaw($forUnion
                ? $this->unionSelectColumns('pos')
                : 'sm.order_num, sm.create_time, sm.cash, sm.mpesa_amount, sm.userid_sales, sm.payment_method, sc_ranked.customer_name as walk_in_name, u.username as created_by, COALESCE(line_totals.order_total, 0) as order_total, COALESCE(line_totals.total_vat, 0) as total_vat');

        $this->applyLegacyDateRangeOnDateColumn($query, 'sm.create_time', $from, $to);

        if ($orderNum !== null && $saleDate !== null) {
            $query->where('sm.order_num', $orderNum)->where('sm.create_time', $saleDate);
        } elseif ($orderNum !== null) {
            $query->where('sm.order_num', $orderNum);
        }

        if ($q !== '') {
            $orderQ = $this->orderNumSearchTerm($q);
            $query->where(function ($sub) use ($q, $orderQ) {
                $sub->where('sm.order_num', 'like', "%{$orderQ}%")
                    ->orWhere('sc_ranked.customer_name', 'like', "%{$q}%")
                    ->orWhere('u.username', 'like', "%{$q}%");
            });
        }

        return $forUnion ? $query : $query->orderByDesc('sm.create_time')->orderByDesc('sm.order_num');
    }

    protected function mobileSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q, ?int $orderNum = null, ?string $saleDate = null, bool $forUnion = false)
    {
        $lineTotals = ($orderNum !== null && $saleDate !== null)
            ? LightStoresLegacySchema::mobileOrderLineTotalsSubquery($connection, $orderNum, $saleDate)
            : LightStoresLegacySchema::mobileListLineTotalsSubquery($connection);
        $users = LightStoresLegacySchema::USERS;

        $query = DB::connection($connection)
            ->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
            ->leftJoin(LightStoresLegacySchema::CUSTOMERS.' as c', 'c.customer_num', '=', 'rm.customer_num')
            ->leftJoinSub($lineTotals, 'line_totals', function ($join) use ($orderNum, $saleDate) {
                $join->on('line_totals.order_num', '=', 'rm.order_num');
                if ($orderNum === null || $saleDate === null) {
                    $join->on('line_totals.sale_date', '=', 'rm.create_time');
                }
            })
            ->leftJoin("{$users} as u", 'u.id', '=', 'rm.user_id')
            ->whereNull('rm.DLT_ON')
            ->selectRaw($forUnion
                ? $this->unionSelectColumns('mobile')
                : 'rm.order_num, rm.create_time, rm.customer_num, rm.order_status, rm.user_id, rm.required_date, rm.delivery_date, c.customer_name, u.username as created_by, COALESCE(line_totals.order_total, 0) as order_total, COALESCE(line_totals.total_vat, 0) as total_vat');

        $this->applyLegacyDateRangeOnDateColumn($query, 'rm.create_time', $from, $to);

        if ($orderNum !== null && $saleDate !== null) {
            $query->where('rm.order_num', $orderNum)->where('rm.create_time', $saleDate);
        } elseif ($orderNum !== null) {
            $query->where('rm.order_num', $orderNum);
        }

        if ($q !== '') {
            $orderQ = $this->orderNumSearchTerm($q);
            $query->where(function ($sub) use ($q, $orderQ) {
                $sub->where('rm.order_num', 'like', "%{$orderQ}%")
                    ->orWhere('c.customer_name', 'like', "%{$q}%")
                    ->orWhere('u.username', 'like', "%{$q}%");
            });
        }

        return $forUnion ? $query : $query->orderByDesc('rm.create_time')->orderByDesc('rm.order_num');
    }

    protected function debtorSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q, ?int $orderNum = null, ?string $saleDate = null, bool $forUnion = false)
    {
        $fromDate = $from?->toDateString();
        $toDate = $to?->toDateString();
        $lineTotals = LightStoresLegacySchema::debtorLineTotalsSubquery($connection, $fromDate, $toDate, $orderNum, $saleDate);
        $users = LightStoresLegacySchema::USERS;

        $query = DB::connection($connection)
            ->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
            ->leftJoin(LightStoresLegacySchema::CUSTOMERS.' as c', 'c.customer_num', '=', 'dm.customer_num')
            ->leftJoinSub($lineTotals, 'line_totals', function ($join) {
                $join->on('line_totals.order_num', '=', 'dm.order_num')
                    ->on('line_totals.sale_date', '=', DB::raw('DATE(dm.create_time)'));
            })
            ->leftJoin("{$users} as u", 'u.id', '=', 'dm.user_id')
            ->whereNull('dm.dlt_on')
            ->selectRaw($forUnion
                ? $this->unionSelectColumns('debtor')
                : 'dm.order_num, dm.create_time, dm.customer_num, dm.payment_status, dm.user_id, c.customer_name, u.username as created_by, COALESCE(line_totals.order_total, 0) as order_total, COALESCE(line_totals.total_vat, 0) as total_vat');

        $this->applyLegacyDateRange($query, 'dm.create_time', $from, $to);

        if ($orderNum !== null && $saleDate !== null) {
            $query->where('dm.order_num', $orderNum)->whereDate('dm.create_time', $saleDate);
        } elseif ($orderNum !== null) {
            $query->where('dm.order_num', $orderNum);
        }

        if ($q !== '') {
            $orderQ = $this->orderNumSearchTerm($q);
            $query->where(function ($sub) use ($q, $orderQ) {
                $sub->where('dm.order_num', 'like', "%{$orderQ}%")
                    ->orWhere('c.customer_name', 'like', "%{$q}%")
                    ->orWhere('u.username', 'like', "%{$q}%");
            });
        }

        return $forUnion ? $query : $query->orderByDesc('dm.create_time')->orderByDesc('dm.order_num');
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentSaleRow(Organization $org, string $channel, object $row): array
    {
        $legacyOrderNum = (int) ($row->order_num ?? 0);
        $saleDate = $this->normalizeSaleDate($row->create_time ?? null);
        $labelPrefix = $this->channelLabelPrefix($channel);

        $materializedSaleId = $this->materializedSaleId($org, $channel, $legacyOrderNum, $saleDate);
        $orderTotal = round((float) ($row->order_total ?? 0), 2);
        $totalVat = round((float) ($row->total_vat ?? 0), 2);

        $base = [
            'archive_source' => 'lightstores',
            'archive_channel' => $channel,
            'legacy_order_num' => $legacyOrderNum,
            'legacy_sale_date' => $saleDate,
            'legacy_order_label' => $labelPrefix.$legacyOrderNum,
            'legacy_channel_prefix' => $labelPrefix,
            'sale_date' => $row->create_time ?? null,
            'created_by' => $row->created_by ?? null,
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
                'customer_name' => $row->walk_in_name ?? $row->customer_name ?? null,
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

    protected function materializedSaleId(Organization $org, string $channel, int $legacyOrderNum, string $saleDate): ?int
    {
        $id = DB::table('sales')
            ->where('organization_id', $org->id)
            ->where('fulfillment_meta->legacy_import', true)
            ->where('fulfillment_meta->legacy_order_num', $legacyOrderNum)
            ->where('fulfillment_meta->legacy_sale_date', $saleDate)
            ->whereIn('fulfillment_meta->legacy_source', LightStoresLegacySchema::legacySourcesForChannel($channel))
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function saleDetail(Organization $org, string $channel, int|string $legacyOrderNum, string $saleDate): array
    {
        $this->assertAvailable($org);

        if (! in_array($channel, ['pos', 'mobile', 'debtor'], true)) {
            throw new RuntimeException('Channel must be pos, mobile, or debtor.');
        }

        $legacyOrderNum = $this->parseLegacyOrderNum($legacyOrderNum);
        $saleDate = $this->normalizeSaleDate($saleDate);
        $connection = $this->connection($org);
        $row = match ($channel) {
            'pos' => $this->posSalesQuery($connection, null, null, '', $legacyOrderNum, $saleDate)->first(),
            'mobile' => $this->mobileSalesQuery($connection, null, null, '', $legacyOrderNum, $saleDate)->first(),
            'debtor' => $this->debtorSalesQuery($connection, null, null, '', $legacyOrderNum, $saleDate)->first(),
        };

        if (! $row) {
            throw new RuntimeException("Legacy {$channel} sale #{$legacyOrderNum} on {$saleDate} was not found.");
        }

        $header = $this->presentSaleRow($org, $channel, $row);
        $header['lines'] = $this->saleLines($connection, $channel, $legacyOrderNum, $saleDate);

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
            ->join(LightStoresLegacySchema::POS_LINES.' as sp', function ($join) {
                $join->on('sp.order_num_ref', '=', 'sm.order_num')
                    ->on('sp.create_time', '=', 'sm.create_time');
            });
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
            ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', function ($join) {
                $join->on('rod.order_no', '=', 'rm.order_num')
                    ->on('rod.create_time', '=', 'rm.create_time');
            })
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
            ->join(LightStoresLegacySchema::POS_LINES.' as sp', function ($join) {
                $join->on('sp.order_num_ref', '=', 'sm.order_num')
                    ->on('sp.create_time', '=', 'sm.create_time');
            });
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
            ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', function ($join) {
                $join->on('rod.order_no', '=', 'rm.order_num')
                    ->on('rod.create_time', '=', 'rm.create_time');
            })
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
    protected function saleLines(string $connection, string $channel, int $legacyOrderNum, string $saleDate): array
    {
        $productTable = LightStoresLegacySchema::PRODUCTS;

        $lines = match ($channel) {
            'pos' => DB::connection($connection)
                ->table(LightStoresLegacySchema::POS_LINES.' as sp')
                ->leftJoin("{$productTable} as p", 'p.product_code', '=', 'sp.productsales_id')
                ->where('sp.order_num_ref', $legacyOrderNum)
                ->whereDate('sp.create_time', $saleDate)
                ->orderBy('sp.id')
                ->get([
                    'sp.id',
                    'sp.create_time',
                    'sp.order_num_ref',
                    'sp.productsales_id',
                    'sp.quantity',
                    'sp.uom',
                    'sp.selling_price',
                    'sp.discount_given',
                    'sp.product_vat',
                    'sp.amount',
                    'p.product_name',
                    'p.main_code',
                ]),
            'mobile' => DB::connection($connection)
                ->table(LightStoresLegacySchema::ROUTE_MASTERS.' as rm')
                ->join(LightStoresLegacySchema::ROUTE_LINES.' as rod', function ($join) {
                    $join->on('rod.order_no', '=', 'rm.order_num')
                        ->on('rod.create_time', '=', 'rm.create_time');
                })
                ->leftJoin("{$productTable} as p", 'p.product_code', '=', 'rod.product_code')
                ->whereNull('rm.DLT_ON')
                ->where('rm.order_num', $legacyOrderNum)
                ->where('rm.create_time', $saleDate)
                ->orderBy('rod.item_code')
                ->orderBy('rod.product_code')
                ->get([
                    'rod.order_no',
                    'rod.create_time',
                    'rod.product_code',
                    'rod.qty_ordered',
                    'rod.uom',
                    'rod.unit_price',
                    'rod.product_vat',
                    'rod.amount',
                    'rod.item_code',
                    'p.product_name',
                    'p.main_code',
                ]),
            'debtor' => DB::connection($connection)
                ->table(LightStoresLegacySchema::DEBTOR_MASTERS.' as dm')
                ->join(LightStoresLegacySchema::DEBTOR_LINES.' as dp', 'dp.order_num', '=', 'dm.order_num')
                ->leftJoin("{$productTable} as p", 'p.product_code', '=', 'dp.product_code')
                ->where('dm.order_num', $legacyOrderNum)
                ->whereDate('dm.create_time', $saleDate)
                ->whereNull('dm.dlt_on')
                ->orderBy('dp.item_code')
                ->orderBy('dp.product_code')
                ->get([
                    'dp.product_code',
                    'dp.quantity',
                    'dp.uom',
                    'dp.selling_price',
                    'dp.discount_given',
                    'dp.product_vat',
                    'dp.amount',
                    'dp.item_code',
                    'p.product_name',
                ]),
        };

        return $lines->map(function ($line) use ($channel) {
            $productCode = (string) ($line->productsales_id ?? $line->product_code ?? $line->item_code ?? '');
            $productName = $line->product_name
                ? (string) $line->product_name
                : ($productCode !== '' ? $productCode : null);

            return [
                'product_code' => $productCode,
                'product_name' => $productName,
                'main_code' => $line->main_code ?? null,
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
