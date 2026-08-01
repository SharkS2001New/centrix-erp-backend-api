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
            'hospitality-arrivals-departures' => $this->arrivalsDepartures($org, $from, $to),
            'hospitality-folio-balances' => $this->folioBalances($org),
            'hospitality-fnb-checks' => $this->fnbChecks($org, $from, $to),
            'hospitality-profit-loss' => $this->profitLoss($org, $from, $to),
            'hospitality-eod-cashier' => $this->eodCashier($org, $from, $to),
            default => ['columns' => [], 'rows' => []],
        };
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
}
