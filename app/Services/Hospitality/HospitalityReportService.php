<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityFolio;
use App\Models\HospitalityReservation;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HospitalityReportService
{
    /**
     * @return array{columns: list<array{key: string, label: string}>, rows: list<array<string, mixed>>}
     */
    public function run(Organization $org, string $slug, ?string $from = null, ?string $to = null): array
    {
        $from = $from ? Carbon::parse($from)->toDateString() : now()->subDays(7)->toDateString();
        $to = $to ? Carbon::parse($to)->toDateString() : now()->toDateString();

        return match ($slug) {
            'hospitality-occupancy' => $this->occupancy($org),
            'hospitality-kpi-occupancy' => $this->kpiOccupancy($org, $from, $to),
            'hospitality-arrivals-departures' => $this->arrivalsDepartures($org, $from, $to),
            'hospitality-folio-balances' => $this->folioBalances($org),
            'hospitality-room-revenue' => $this->roomRevenue($org, $from, $to),
            'hospitality-fnb-checks' => $this->fnbChecks($org, $from, $to),
            'hospitality-fnb-by-outlet' => $this->fnbByOutlet($org, $from, $to),
            'hospitality-fnb-by-hour' => $this->fnbByHour($org, $from, $to),
            'hospitality-fnb-by-category' => $this->fnbByCategory($org, $from, $to),
            'hospitality-open-checks' => $this->openChecks($org, $from, $to),
            'hospitality-voids' => $this->voids($org, $from, $to),
            'hospitality-manager-flash' => $this->managerFlash($org, $from, $to),
            'hospitality-profit-loss' => $this->profitLoss($org, $from, $to),
            'hospitality-eod-cashier' => $this->eodCashier($org, $from, $to),
            'hospitality-consumption-variance' => $this->consumptionVariance($org, $from, $to),
            default => ['columns' => [], 'rows' => []],
        };
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return [
            'hospitality-occupancy',
            'hospitality-kpi-occupancy',
            'hospitality-arrivals-departures',
            'hospitality-folio-balances',
            'hospitality-room-revenue',
            'hospitality-fnb-checks',
            'hospitality-fnb-by-outlet',
            'hospitality-fnb-by-hour',
            'hospitality-fnb-by-category',
            'hospitality-open-checks',
            'hospitality-voids',
            'hospitality-manager-flash',
            'hospitality-profit-loss',
            'hospitality-eod-cashier',
            'hospitality-consumption-variance',
        ];
    }

    protected function occupancy(Organization $org): array
    {
        $rows = HospitalityRoom::query()
            ->with('roomType:id,name')
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->orderBy('room_number')
            ->get()
            ->map(fn (HospitalityRoom $r) => [
                'room_number' => $r->room_number,
                'floor' => $r->floor,
                'room_type' => $r->roomType?->name,
                'status' => $r->status,
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'room_number', 'label' => 'Room'],
                ['key' => 'floor', 'label' => 'Floor'],
                ['key' => 'room_type', 'label' => 'Type'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $rows,
        ];
    }

    protected function arrivalsDepartures(Organization $org, string $from, string $to): array
    {
        $rows = HospitalityReservation::query()
            ->with(['room:id,room_number', 'roomType:id,name'])
            ->where('organization_id', $org->id)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('arrival_date', [$from, $to])
                    ->orWhereBetween('departure_date', [$from, $to]);
            })
            ->orderBy('arrival_date')
            ->get()
            ->map(fn (HospitalityReservation $r) => [
                'confirmation_code' => $r->confirmation_code,
                'guest_name' => $r->guest_name,
                'room_type' => $r->roomType?->name,
                'room_number' => $r->room?->room_number,
                'arrival_date' => optional($r->arrival_date)?->toDateString(),
                'departure_date' => optional($r->departure_date)?->toDateString(),
                'status' => $r->status,
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'confirmation_code', 'label' => 'Code'],
                ['key' => 'guest_name', 'label' => 'Guest'],
                ['key' => 'room_type', 'label' => 'Type'],
                ['key' => 'room_number', 'label' => 'Room'],
                ['key' => 'arrival_date', 'label' => 'Arrival'],
                ['key' => 'departure_date', 'label' => 'Departure'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $rows,
        ];
    }

    protected function folioBalances(Organization $org): array
    {
        $rows = HospitalityFolio::query()
            ->with('room:id,room_number')
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->orderByDesc('balance')
            ->get()
            ->map(fn (HospitalityFolio $f) => [
                'folio_number' => $f->folio_number,
                'guest_name' => $f->guest_name,
                'room_number' => $f->room?->room_number,
                'balance' => (float) $f->balance,
                'checked_in_at' => optional($f->checked_in_at)?->toDateString(),
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'folio_number', 'label' => 'Folio'],
                ['key' => 'guest_name', 'label' => 'Guest'],
                ['key' => 'room_number', 'label' => 'Room'],
                ['key' => 'balance', 'label' => 'Balance'],
                ['key' => 'checked_in_at', 'label' => 'Checked in'],
            ],
            'rows' => $rows,
        ];
    }

    protected function fnbChecks(Organization $org, string $from, string $to): array
    {
        $rows = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'settled'])
            ->whereBetween(DB::raw('DATE(closed_at)'), [$from, $to])
            ->orderByDesc('closed_at')
            ->limit(500)
            ->get()
            ->map(fn (HospitalityCheck $c) => [
                'check_number' => $c->check_number,
                'status' => $c->status,
                'total' => (float) $c->total,
                'amount_paid' => (float) $c->amount_paid,
                'service_mode' => $c->service_mode,
                'closed_at' => optional($c->closed_at)?->toDateTimeString(),
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'check_number', 'label' => 'Check'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'total', 'label' => 'Total'],
                ['key' => 'amount_paid', 'label' => 'Paid'],
                ['key' => 'service_mode', 'label' => 'Mode'],
                ['key' => 'closed_at', 'label' => 'Closed'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Hospitality P&L: F&B + room revenue − F&B COGS (inventory HOSPITALITY_SALE costs).
     * Gross profit = revenue − COGS. Net = gross (no shared opex allocated here).
     */
    protected function profitLoss(Organization $org, string $from, string $to): array
    {
        $fnbRevenue = (float) HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'settled'])
            ->whereBetween(DB::raw('DATE(closed_at)'), [$from, $to])
            ->sum('total');

        $fnbVat = (float) HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'settled'])
            ->whereBetween(DB::raw('DATE(closed_at)'), [$from, $to])
            ->sum('vat_total');

        $roomRevenue = 0.0;
        $otherFolioRevenue = 0.0;
        if (Schema::hasTable('hospitality_folio_charges')) {
            $roomRevenue = (float) DB::table('hospitality_folio_charges')
                ->where('organization_id', $org->id)
                ->where('charge_type', 'room')
                ->whereBetween(DB::raw('DATE(COALESCE(business_date, posted_at, created_at))'), [$from, $to])
                ->sum('amount');
            $otherFolioRevenue = (float) DB::table('hospitality_folio_charges')
                ->where('organization_id', $org->id)
                ->where('charge_type', '!=', 'room')
                ->whereBetween(DB::raw('DATE(COALESCE(business_date, posted_at, created_at))'), [$from, $to])
                ->sum('amount');
        }

        // Avoid double-counting F&B posted to folio via room charge (fnb charges linked to checks).
        $fnbPostedToFolio = 0.0;
        if (Schema::hasTable('hospitality_folio_charges')) {
            $fnbPostedToFolio = (float) DB::table('hospitality_folio_charges')
                ->where('organization_id', $org->id)
                ->where('charge_type', 'fnb')
                ->whereNotNull('check_id')
                ->whereBetween(DB::raw('DATE(COALESCE(posted_at, created_at))'), [$from, $to])
                ->sum('amount');
        }
        // Room charge settles mark the check paid AND post folio fnb — count check revenue once.
        $fnbRevenueNet = max(0, $fnbRevenue); // check total is source of truth for paid F&B
        $otherFolioOnly = max(0, $otherFolioRevenue - $fnbPostedToFolio);

        $cogs = 0.0;
        if (Schema::hasTable('inventory_transactions')) {
            $cogs = (float) DB::table('inventory_transactions')
                ->where('organization_id', $org->id)
                ->where('transaction_type', HospitalityCheckStockService::TXN_TYPE)
                ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
                ->selectRaw('COALESCE(SUM(ABS(quantity_change) * COALESCE(unit_cost, 0)), 0) as cogs')
                ->value('cogs');
        }

        // Fallback COGS estimate when stock deduct is off: recipe ingredients × last_cost × sold qty.
        if ($cogs <= 0 && Schema::hasTable('hospitality_recipes')) {
            $cogs = $this->estimateRecipeCogs($org, $from, $to);
        }

        $grossRevenue = round($fnbRevenueNet + $roomRevenue + $otherFolioOnly, 2);
        $cogs = round($cogs, 2);
        $grossProfit = round($grossRevenue - $cogs, 2);
        $marginPct = $grossRevenue > 0 ? round(($grossProfit / $grossRevenue) * 100, 1) : 0.0;

        $row = [
            'period_from' => $from,
            'period_to' => $to,
            'fnb_revenue' => round($fnbRevenueNet, 2),
            'fnb_vat' => round($fnbVat, 2),
            'room_revenue' => round($roomRevenue, 2),
            'other_folio_revenue' => round($otherFolioOnly, 2),
            'gross_revenue' => $grossRevenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin_pct' => $marginPct,
            'net_profit' => $grossProfit,
        ];

        return [
            'columns' => [
                ['key' => 'period_from', 'label' => 'From'],
                ['key' => 'period_to', 'label' => 'To'],
                ['key' => 'fnb_revenue', 'label' => 'F&B revenue'],
                ['key' => 'room_revenue', 'label' => 'Room revenue'],
                ['key' => 'other_folio_revenue', 'label' => 'Other folio'],
                ['key' => 'gross_revenue', 'label' => 'Gross revenue'],
                ['key' => 'cogs', 'label' => 'COGS'],
                ['key' => 'gross_profit', 'label' => 'Gross profit'],
                ['key' => 'gross_margin_pct', 'label' => 'Margin %'],
                ['key' => 'net_profit', 'label' => 'Net profit'],
            ],
            'rows' => [$row],
        ];
    }

    protected function estimateRecipeCogs(Organization $org, string $from, string $to): float
    {
        $lines = DB::table('hospitality_check_lines as hcl')
            ->join('hospitality_checks as hc', 'hc.id', '=', 'hcl.check_id')
            ->where('hc.organization_id', $org->id)
            ->whereIn('hc.status', ['paid', 'settled'])
            ->whereBetween(DB::raw('DATE(hc.closed_at)'), [$from, $to])
            ->whereNotNull('hcl.product_code')
            ->select('hcl.product_code', DB::raw('SUM(hcl.qty) as qty'))
            ->groupBy('hcl.product_code')
            ->get();

        $total = 0.0;
        foreach ($lines as $line) {
            $code = (string) $line->product_code;
            $soldQty = (float) $line->qty;
            $recipe = DB::table('hospitality_recipes')
                ->where('organization_id', $org->id)
                ->where('menu_product_code', $code)
                ->where('is_active', true)
                ->first();
            if (! $recipe) {
                $cost = (float) DB::table('products')
                    ->where('organization_id', $org->id)
                    ->where('product_code', $code)
                    ->value('last_cost_price');
                $total += $soldQty * max(0, $cost);
                continue;
            }
            if (($recipe->deduct_mode ?? '') === 'direct') {
                $cost = (float) DB::table('products')
                    ->where('organization_id', $org->id)
                    ->where('product_code', $code)
                    ->value('last_cost_price');
                $total += $soldQty * max(0, $cost);
                continue;
            }
            if (($recipe->deduct_mode ?? '') === 'none') {
                continue;
            }
            $ings = DB::table('hospitality_recipe_ingredients')
                ->where('recipe_id', $recipe->id)
                ->get();
            foreach ($ings as $ing) {
                $eff = (float) $ing->quantity * (1 + ((float) $ing->waste_percent / 100));
                $cost = (float) DB::table('products')
                    ->where('organization_id', $org->id)
                    ->where('product_code', $ing->ingredient_product_code)
                    ->value('last_cost_price');
                $total += $soldQty * $eff * max(0, $cost);
            }
        }

        return round($total, 2);
    }

    /** End-of-day F&B sales grouped by cashier (closed_by). */
    protected function eodCashier(Organization $org, string $from, string $to): array
    {
        $checks = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(closed_at, updated_at))'), [$from, $to])
            ->get();

        $byCashier = [];
        foreach ($checks as $check) {
            $cid = (int) ($check->closed_by ?: $check->opened_by ?: 0);
            if (! isset($byCashier[$cid])) {
                $byCashier[$cid] = [
                    'cashier_id' => $cid ?: null,
                    'cashier_name' => 'Unassigned',
                    'checks' => 0,
                    'gross_sales' => 0.0,
                    'vat_total' => 0.0,
                    'amount_paid' => 0.0,
                    'cash' => 0.0,
                    'mpesa' => 0.0,
                    'card_bank' => 0.0,
                    'room_charge' => 0.0,
                    'other' => 0.0,
                ];
            }
            $byCashier[$cid]['checks']++;
            $byCashier[$cid]['gross_sales'] += (float) $check->total;
            $byCashier[$cid]['vat_total'] += (float) $check->vat_total;
            $byCashier[$cid]['amount_paid'] += (float) $check->amount_paid;
        }

        $userIds = array_filter(array_keys($byCashier));
        $names = $userIds
            ? DB::table('users')->whereIn('id', $userIds)->pluck('full_name', 'id')
            : collect();

        $payments = DB::table('hospitality_check_payments as p')
            ->join('hospitality_checks as c', 'c.id', '=', 'p.check_id')
            ->where('c.organization_id', $org->id)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$from, $to])
            ->select('c.closed_by', 'c.opened_by', 'p.method_code', DB::raw('SUM(p.amount) as amt'))
            ->groupBy('c.closed_by', 'c.opened_by', 'p.method_code')
            ->get();

        foreach ($payments as $p) {
            $cid = (int) ($p->closed_by ?: $p->opened_by ?: 0);
            if (! isset($byCashier[$cid])) {
                continue;
            }
            $amt = (float) $p->amt;
            $code = strtoupper((string) $p->method_code);
            if ($code === 'CASH') {
                $byCashier[$cid]['cash'] += $amt;
            } elseif ($code === 'MPESA') {
                $byCashier[$cid]['mpesa'] += $amt;
            } elseif ($code === 'ROOM') {
                $byCashier[$cid]['room_charge'] += $amt;
            } elseif (in_array($code, ['CARD', 'EQUITY', 'KCB', 'BANK', 'OTHER', 'CHEQUE'], true)) {
                $byCashier[$cid]['card_bank'] += $amt;
            } else {
                $byCashier[$cid]['other'] += $amt;
            }
        }

        $rows = [];
        foreach ($byCashier as $cid => $row) {
            $row['cashier_name'] = $cid
                ? (string) ($names[$cid] ?? ('User #'.$cid))
                : 'Unassigned';
            foreach (['gross_sales', 'vat_total', 'amount_paid', 'cash', 'mpesa', 'card_bank', 'room_charge', 'other'] as $k) {
                $row[$k] = round($row[$k], 2);
            }
            $row['sale_date'] = $from === $to ? $from : "{$from} → {$to}";
            $rows[] = $row;
        }

        usort($rows, fn ($a, $b) => $b['gross_sales'] <=> $a['gross_sales']);

        return [
            'columns' => [
                ['key' => 'sale_date', 'label' => 'Date'],
                ['key' => 'cashier_name', 'label' => 'Cashier'],
                ['key' => 'checks', 'label' => 'Checks'],
                ['key' => 'gross_sales', 'label' => 'Gross sales'],
                ['key' => 'vat_total', 'label' => 'VAT'],
                ['key' => 'amount_paid', 'label' => 'Collected'],
                ['key' => 'cash', 'label' => 'Cash'],
                ['key' => 'mpesa', 'label' => 'M-Pesa'],
                ['key' => 'card_bank', 'label' => 'Card/Bank'],
                ['key' => 'room_charge', 'label' => 'Room charge'],
                ['key' => 'other', 'label' => 'Other'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Daily occupancy KPIs: available rooms, occupied, occupancy %, room nights, ADR, RevPAR.
     */
    protected function kpiOccupancy(Organization $org, string $from, string $to): array
    {
        $activeRooms = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->get(['id', 'status']);
        $totalRooms = $activeRooms->count();
        $oooRooms = $activeRooms->where('status', 'ooo')->count();
        $sellable = max(0, $totalRooms - $oooRooms);
        $occupiedNow = $activeRooms->where('status', 'occupied')->count();

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $rows = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $roomNights = 0.0;
            $roomRevenue = 0.0;
            if (Schema::hasTable('hospitality_folio_charges')) {
                $roomNights = (float) DB::table('hospitality_folio_charges')
                    ->where('organization_id', $org->id)
                    ->where('charge_type', 'room')
                    ->whereDate(DB::raw('COALESCE(business_date, posted_at, created_at)'), $date)
                    ->count();
                $roomRevenue = (float) DB::table('hospitality_folio_charges')
                    ->where('organization_id', $org->id)
                    ->where('charge_type', 'room')
                    ->whereDate(DB::raw('COALESCE(business_date, posted_at, created_at)'), $date)
                    ->sum('amount');
            }
            // Fallback: open folios that overlap the night (checked in on/before date, not checked out before date).
            if ($roomNights <= 0) {
                $roomNights = (float) HospitalityFolio::query()
                    ->where('organization_id', $org->id)
                    ->where('status', 'open')
                    ->whereNotNull('room_id')
                    ->whereDate('checked_in_at', '<=', $date)
                    ->where(function ($q) use ($date) {
                        $q->whereNull('checked_out_at')->orWhereDate('checked_out_at', '>', $date);
                    })
                    ->count();
            }

            $occPct = $sellable > 0 ? round(($roomNights / $sellable) * 100, 1) : 0.0;
            $adr = $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : 0.0;
            $revpar = $sellable > 0 ? round($roomRevenue / $sellable, 2) : 0.0;

            $rows[] = [
                'business_date' => $date,
                'rooms_total' => $totalRooms,
                'rooms_ooo' => $oooRooms,
                'rooms_available' => $sellable,
                'room_nights' => (int) $roomNights,
                'occupancy_pct' => $occPct,
                'room_revenue' => round($roomRevenue, 2),
                'adr' => $adr,
                'revpar' => $revpar,
                'occupied_now' => $occupiedNow,
            ];
        }

        return [
            'columns' => [
                ['key' => 'business_date', 'label' => 'Date'],
                ['key' => 'rooms_available', 'label' => 'Available'],
                ['key' => 'rooms_ooo', 'label' => 'OOO'],
                ['key' => 'room_nights', 'label' => 'Room nights'],
                ['key' => 'occupancy_pct', 'label' => 'Occupancy %'],
                ['key' => 'room_revenue', 'label' => 'Room revenue'],
                ['key' => 'adr', 'label' => 'ADR'],
                ['key' => 'revpar', 'label' => 'RevPAR'],
            ],
            'rows' => $rows,
        ];
    }

    protected function roomRevenue(Organization $org, string $from, string $to): array
    {
        if (! Schema::hasTable('hospitality_folio_charges')) {
            return ['columns' => [], 'rows' => []];
        }

        $rows = DB::table('hospitality_folio_charges as c')
            ->leftJoin('hospitality_folios as f', 'f.id', '=', 'c.folio_id')
            ->leftJoin('hospitality_rooms as r', 'r.id', '=', 'f.room_id')
            ->leftJoin('hospitality_room_types as rt', 'rt.id', '=', 'r.room_type_id')
            ->leftJoin('hospitality_reservations as res', 'res.folio_id', '=', 'f.id')
            ->leftJoin('hospitality_rate_plans as rp', 'rp.id', '=', 'res.rate_plan_id')
            ->where('c.organization_id', $org->id)
            ->where('c.charge_type', 'room')
            ->whereBetween(DB::raw('DATE(COALESCE(c.business_date, c.posted_at, c.created_at))'), [$from, $to])
            ->groupBy('rt.name', 'rp.name')
            ->orderByDesc(DB::raw('SUM(c.amount)'))
            ->selectRaw('COALESCE(rt.name, \'Unassigned\') as room_type')
            ->selectRaw('COALESCE(rp.name, \'Standard / base rate\') as rate_plan')
            ->selectRaw('COUNT(*) as room_nights')
            ->selectRaw('ROUND(SUM(c.amount), 2) as revenue')
            ->selectRaw('ROUND(SUM(c.amount) / NULLIF(COUNT(*), 0), 2) as adr')
            ->get()
            ->map(fn ($r) => [
                'room_type' => $r->room_type,
                'rate_plan' => $r->rate_plan,
                'room_nights' => (int) $r->room_nights,
                'revenue' => (float) $r->revenue,
                'adr' => (float) $r->adr,
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'room_type', 'label' => 'Room type'],
                ['key' => 'rate_plan', 'label' => 'Rate plan'],
                ['key' => 'room_nights', 'label' => 'Room nights'],
                ['key' => 'revenue', 'label' => 'Revenue'],
                ['key' => 'adr', 'label' => 'ADR'],
            ],
            'rows' => $rows,
        ];
    }

    protected function fnbByOutlet(Organization $org, string $from, string $to): array
    {
        $rows = DB::table('hospitality_checks as c')
            ->leftJoin('hospitality_outlets as o', 'o.id', '=', 'c.outlet_id')
            ->where('c.organization_id', $org->id)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$from, $to])
            ->groupBy('o.id', 'o.code', 'o.name')
            ->orderByDesc(DB::raw('SUM(c.total)'))
            ->selectRaw('COALESCE(o.code, \'MAIN\') as outlet_code')
            ->selectRaw('COALESCE(o.name, \'Main outlet\') as outlet_name')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw('ROUND(SUM(c.total), 2) as gross_sales')
            ->selectRaw('ROUND(SUM(c.amount_paid), 2) as collected')
            ->selectRaw('ROUND(SUM(c.vat_total), 2) as vat_total')
            ->get()
            ->map(fn ($r) => [
                'outlet_code' => $r->outlet_code,
                'outlet_name' => $r->outlet_name,
                'checks' => (int) $r->checks,
                'gross_sales' => (float) $r->gross_sales,
                'collected' => (float) $r->collected,
                'vat_total' => (float) $r->vat_total,
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'outlet_code', 'label' => 'Outlet'],
                ['key' => 'outlet_name', 'label' => 'Name'],
                ['key' => 'checks', 'label' => 'Checks'],
                ['key' => 'gross_sales', 'label' => 'Gross sales'],
                ['key' => 'collected', 'label' => 'Collected'],
                ['key' => 'vat_total', 'label' => 'VAT'],
            ],
            'rows' => $rows,
        ];
    }

    protected function fnbByHour(Organization $org, string $from, string $to): array
    {
        $rows = DB::table('hospitality_checks')
            ->where('organization_id', $org->id)
            ->whereIn('status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(closed_at, updated_at))'), [$from, $to])
            ->groupBy(DB::raw('HOUR(COALESCE(closed_at, updated_at))'))
            ->orderBy(DB::raw('HOUR(COALESCE(closed_at, updated_at))'))
            ->selectRaw('HOUR(COALESCE(closed_at, updated_at)) as hour')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw('ROUND(SUM(total), 2) as gross_sales')
            ->selectRaw('ROUND(SUM(amount_paid), 2) as collected')
            ->get()
            ->map(fn ($r) => [
                'hour' => sprintf('%02d:00', (int) $r->hour),
                'checks' => (int) $r->checks,
                'gross_sales' => (float) $r->gross_sales,
                'collected' => (float) $r->collected,
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'hour', 'label' => 'Hour'],
                ['key' => 'checks', 'label' => 'Checks'],
                ['key' => 'gross_sales', 'label' => 'Gross sales'],
                ['key' => 'collected', 'label' => 'Collected'],
            ],
            'rows' => $rows,
        ];
    }

    protected function fnbByCategory(Organization $org, string $from, string $to): array
    {
        $rows = DB::table('hospitality_check_lines as l')
            ->join('hospitality_checks as c', 'c.id', '=', 'l.check_id')
            ->leftJoin('products as p', function ($join) {
                $join->on('p.product_code', '=', 'l.product_code')
                    ->on('p.organization_id', '=', 'c.organization_id');
            })
            ->leftJoin('sub_categories as sc', 'sc.id', '=', 'p.subcategory_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'sc.category_id')
            ->where('c.organization_id', $org->id)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid'])
            ->whereBetween(DB::raw('DATE(COALESCE(c.closed_at, c.updated_at))'), [$from, $to])
            ->groupBy('cat.category_name')
            ->orderByDesc(DB::raw('SUM(l.line_total)'))
            ->selectRaw('COALESCE(cat.category_name, \'Uncategorised\') as category')
            ->selectRaw('COUNT(DISTINCT c.id) as checks')
            ->selectRaw('ROUND(SUM(l.qty), 2) as qty_sold')
            ->selectRaw('ROUND(SUM(l.line_total), 2) as sales')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'checks' => (int) $r->checks,
                'qty_sold' => (float) $r->qty_sold,
                'sales' => (float) $r->sales,
            ])
            ->all();

        return [
            'columns' => [
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'checks', 'label' => 'Checks'],
                ['key' => 'qty_sold', 'label' => 'Qty sold'],
                ['key' => 'sales', 'label' => 'Sales'],
            ],
            'rows' => $rows,
        ];
    }

    protected function openChecks(Organization $org, string $from, string $to): array
    {
        $today = now()->startOfDay();
        $rows = HospitalityCheck::query()
            ->with(['outlet:id,code,name', 'floorTable:id,code,label'])
            ->where('organization_id', $org->id)
            ->whereIn('status', ['unpaid', 'partially_paid', 'held', 'open'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween(DB::raw('DATE(opened_at)'), [$from, $to])
                    ->orWhereBetween(DB::raw('DATE(updated_at)'), [$from, $to]);
            })
            ->orderBy('opened_at')
            ->limit(500)
            ->get()
            ->map(function (HospitalityCheck $c) use ($today) {
                $opened = $c->opened_at ? Carbon::parse($c->opened_at)->startOfDay() : $today;
                $ageDays = (int) $opened->diffInDays($today);
                $balance = round(max(0, (float) $c->total - (float) $c->amount_paid), 2);
                $bucket = $ageDays <= 0 ? 'Today' : ($ageDays <= 1 ? '1 day' : ($ageDays <= 3 ? '2–3 days' : ($ageDays <= 7 ? '4–7 days' : '8+ days')));

                return [
                    'check_number' => $c->check_number,
                    'status' => $c->status === 'held' ? 'unpaid' : $c->status,
                    'outlet' => $c->outlet?->code ?? 'MAIN',
                    'table' => $c->floorTable?->label ?? $c->floorTable?->code,
                    'total' => (float) $c->total,
                    'amount_paid' => (float) $c->amount_paid,
                    'balance_due' => $balance,
                    'age_days' => $ageDays,
                    'aging_bucket' => $bucket,
                    'opened_at' => optional($c->opened_at)?->toDateTimeString(),
                ];
            })
            ->all();

        return [
            'columns' => [
                ['key' => 'check_number', 'label' => 'Check'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'outlet', 'label' => 'Outlet'],
                ['key' => 'table', 'label' => 'Table'],
                ['key' => 'total', 'label' => 'Total'],
                ['key' => 'amount_paid', 'label' => 'Paid'],
                ['key' => 'balance_due', 'label' => 'Balance'],
                ['key' => 'aging_bucket', 'label' => 'Aging'],
                ['key' => 'opened_at', 'label' => 'Opened'],
            ],
            'rows' => $rows,
        ];
    }

    protected function voids(Organization $org, string $from, string $to): array
    {
        $rows = HospitalityCheck::query()
            ->with('outlet:id,code,name')
            ->where('organization_id', $org->id)
            ->where('status', 'void')
            ->whereBetween(DB::raw('DATE(COALESCE(closed_at, updated_at))'), [$from, $to])
            ->orderByDesc('closed_at')
            ->limit(500)
            ->get()
            ->map(fn (HospitalityCheck $c) => [
                'check_number' => $c->check_number,
                'outlet' => $c->outlet?->code ?? 'MAIN',
                'total' => (float) $c->total,
                'lines' => $c->lines()->count(),
                'opened_by' => $c->opened_by,
                'voided_at' => optional($c->closed_at)?->toDateTimeString(),
            ])
            ->all();

        $userIds = array_values(array_unique(array_filter(array_column($rows, 'opened_by'))));
        $names = $userIds
            ? DB::table('users')->whereIn('id', $userIds)->pluck('full_name', 'id')
            : collect();
        foreach ($rows as &$row) {
            $uid = $row['opened_by'];
            $row['cashier'] = $uid ? (string) ($names[$uid] ?? ('User #'.$uid)) : '—';
            unset($row['opened_by']);
        }
        unset($row);

        return [
            'columns' => [
                ['key' => 'check_number', 'label' => 'Check'],
                ['key' => 'outlet', 'label' => 'Outlet'],
                ['key' => 'cashier', 'label' => 'Opened by'],
                ['key' => 'lines', 'label' => 'Lines'],
                ['key' => 'total', 'label' => 'Total voided'],
                ['key' => 'voided_at', 'label' => 'Voided at'],
            ],
            'rows' => $rows,
        ];
    }

    /** Manager flash for a single day (defaults to `to` / `from` when equal). */
    protected function managerFlash(Organization $org, string $from, string $to): array
    {
        $date = $from === $to ? $from : $to;
        $kpi = $this->kpiOccupancy($org, $date, $date);
        $kpiRow = $kpi['rows'][0] ?? [];
        $fnb = $this->fnbByOutlet($org, $date, $date);
        $fnbGross = array_sum(array_column($fnb['rows'], 'gross_sales'));
        $fnbCollected = array_sum(array_column($fnb['rows'], 'collected'));
        $eod = $this->eodCashier($org, $date, $date);
        $openFolios = HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->count();
        $openBalance = (float) HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->sum('balance');
        $openChecks = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', ['unpaid', 'partially_paid', 'held', 'open'])
            ->count();
        $arrivals = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->whereDate('arrival_date', $date)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();
        $departures = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->whereDate('departure_date', $date)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->count();

        $tenders = ['cash' => 0.0, 'mpesa' => 0.0, 'card_bank' => 0.0, 'room_charge' => 0.0, 'other' => 0.0];
        foreach ($eod['rows'] as $row) {
            foreach (array_keys($tenders) as $k) {
                $tenders[$k] += (float) ($row[$k] ?? 0);
            }
        }

        $row = [
            'business_date' => $date,
            'occupancy_pct' => $kpiRow['occupancy_pct'] ?? 0,
            'room_nights' => $kpiRow['room_nights'] ?? 0,
            'adr' => $kpiRow['adr'] ?? 0,
            'revpar' => $kpiRow['revpar'] ?? 0,
            'room_revenue' => $kpiRow['room_revenue'] ?? 0,
            'fnb_gross' => round($fnbGross, 2),
            'fnb_collected' => round($fnbCollected, 2),
            'cash' => round($tenders['cash'], 2),
            'mpesa' => round($tenders['mpesa'], 2),
            'card_bank' => round($tenders['card_bank'], 2),
            'room_charge' => round($tenders['room_charge'], 2),
            'arrivals' => $arrivals,
            'departures' => $departures,
            'open_folios' => $openFolios,
            'open_folio_balance' => round($openBalance, 2),
            'open_fnb_checks' => $openChecks,
        ];

        return [
            'columns' => [
                ['key' => 'business_date', 'label' => 'Date'],
                ['key' => 'occupancy_pct', 'label' => 'Occ %'],
                ['key' => 'adr', 'label' => 'ADR'],
                ['key' => 'revpar', 'label' => 'RevPAR'],
                ['key' => 'room_revenue', 'label' => 'Room rev'],
                ['key' => 'fnb_gross', 'label' => 'F&B gross'],
                ['key' => 'fnb_collected', 'label' => 'F&B collected'],
                ['key' => 'cash', 'label' => 'Cash'],
                ['key' => 'mpesa', 'label' => 'M-Pesa'],
                ['key' => 'card_bank', 'label' => 'Card/Bank'],
                ['key' => 'arrivals', 'label' => 'Arrivals'],
                ['key' => 'departures', 'label' => 'Departures'],
                ['key' => 'open_folios', 'label' => 'Open folios'],
                ['key' => 'open_folio_balance', 'label' => 'Folio bal'],
                ['key' => 'open_fnb_checks', 'label' => 'Open checks'],
            ],
            'rows' => [$row],
        ];
    }

    /** Theoretical recipe COGS vs actual HOSPITALITY_SALE inventory cost. */
    protected function consumptionVariance(Organization $org, string $from, string $to): array
    {
        $theoretical = $this->estimateRecipeCogs($org, $from, $to);
        $actual = 0.0;
        if (Schema::hasTable('inventory_transactions')) {
            $actual = (float) DB::table('inventory_transactions')
                ->where('organization_id', $org->id)
                ->where('transaction_type', HospitalityCheckStockService::TXN_TYPE)
                ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
                ->selectRaw('COALESCE(SUM(ABS(quantity_change) * COALESCE(unit_cost, 0)), 0) as cogs')
                ->value('cogs');
        }
        $actual = round($actual, 2);
        $variance = round($actual - $theoretical, 2);
        $pct = $theoretical > 0 ? round(($variance / $theoretical) * 100, 1) : 0.0;

        return [
            'columns' => [
                ['key' => 'period_from', 'label' => 'From'],
                ['key' => 'period_to', 'label' => 'To'],
                ['key' => 'theoretical_cogs', 'label' => 'Theoretical COGS'],
                ['key' => 'actual_cogs', 'label' => 'Actual COGS'],
                ['key' => 'variance', 'label' => 'Variance'],
                ['key' => 'variance_pct', 'label' => 'Variance %'],
            ],
            'rows' => [[
                'period_from' => $from,
                'period_to' => $to,
                'theoretical_cogs' => $theoretical,
                'actual_cogs' => $actual,
                'variance' => $variance,
                'variance_pct' => $pct,
            ]],
        ];
    }
}
