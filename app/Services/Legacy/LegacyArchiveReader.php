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
     * Legacy archive exposes historical sales only — catalog and customers live in Centrix.
     *
     * @return array<string, int>
     */
    protected function salesCounts(Organization $org): array
    {
        $legacy = DB::connection($this->connection($org));

        return [
            'sales_pos' => (int) $legacy->table('sale_masters')->count(),
            'sales_mobile' => (int) $legacy->table('route_master')->whereNull('DLT_ON')->count(),
            'sales_debtor' => (int) $legacy->table('debtor_masters')->whereNull('dlt_on')->count(),
        ];
    }

    /**
     * @deprecated Use salesCounts() — kept for internal report merges.
     *
     * @return array<string, int>
     */
    protected function tableCounts(Organization $org): array
    {
        return $this->salesCounts($org);
    }

    /**
     * @return array{transactions: int, order_total: float, total_vat: float, by_channel: array<string, array{transactions: int, order_total: float}>}
     */
    public function salesSummary(Organization $org, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->assertAvailable($org);

        $legacy = DB::connection($this->connection($org));

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
    public function listSales(Organization $org, array $filters = []): array
    {
        $this->assertAvailable($org);

        $channel = $filters['channel'] ?? null;
        $from = isset($filters['from_date']) ? Carbon::parse($filters['from_date'])->startOfDay() : null;
        $to = isset($filters['to_date']) ? Carbon::parse($filters['to_date'])->endOfDay() : null;
        $q = trim((string) ($filters['q'] ?? ''));
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);

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
        $query = DB::connection($connection)
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

    protected function mobileSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q)
    {
        $query = DB::connection($connection)
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

    protected function debtorSalesQuery(string $connection, ?Carbon $from, ?Carbon $to, string $q)
    {
        $query = DB::connection($connection)
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
    protected function presentSaleRow(Organization $org, string $channel, object $row): array
    {
        $legacyOrderNum = (int) ($row->order_num ?? 0);
        $labelPrefix = match ($channel) {
            'mobile' => 'M',
            'debtor' => 'D',
            default => 'R',
        };

        $materializedSaleId = $this->materializedSaleId($org, $channel, $legacyOrderNum);

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

    protected function materializedSaleId(Organization $org, string $channel, int $legacyOrderNum): ?int
    {
        $legacySource = match ($channel) {
            'mobile' => 'route_master',
            'debtor' => 'debtor_masters',
            default => 'sale_masters',
        };

        $id = DB::table('sales')
            ->where('organization_id', $org->id)
            ->where('fulfillment_meta->legacy_import', true)
            ->where('fulfillment_meta->legacy_order_num', $legacyOrderNum)
            ->where('fulfillment_meta->legacy_source', $legacySource)
            ->value('id');

        return $id ? (int) $id : null;
    }
}
