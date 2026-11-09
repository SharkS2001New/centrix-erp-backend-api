<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityFolio;
use App\Models\HospitalityNightAudit;
use App\Models\HospitalityReservation;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HospitalityDashboardService
{
    /**
     * @param  array{from_date?: string|null, to_date?: string|null}  $filters
     */
    public function summary(Organization $org, array $filters = []): array
    {
        [$from, $to] = $this->resolveDateRange($filters);
        $isSingleDay = $from === $to;
        $isToday = $isSingleDay && $from === now()->toDateString();
        $priorDay = Carbon::parse($from)->subDay()->toDateString();

        $roomCounts = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $occupied = (int) ($roomCounts['occupied'] ?? 0);
        $dirty = (int) ($roomCounts['dirty'] ?? 0);
        $vacant = (int) ($roomCounts['vacant'] ?? 0);
        $clean = (int) ($roomCounts['clean'] ?? 0);
        $ooo = (int) ($roomCounts['ooo'] ?? 0);
        $totalRooms = (int) $roomCounts->sum();
        $sellable = max(0, $totalRooms - $ooo);

        $arrivalsQuery = HospitalityReservation::query()
            ->with(['room:id,room_number', 'roomType:id,name'])
            ->where('organization_id', $org->id)
            ->whereNotIn('status', ['cancelled', 'no_show', 'checked_out']);
        $this->constrainDateColumn($arrivalsQuery, 'arrival_date', $from, $to);

        $departuresQuery = HospitalityReservation::query()
            ->with(['room:id,room_number', 'roomType:id,name'])
            ->where('organization_id', $org->id)
            ->whereIn('status', ['checked_in', 'booked']);
        $this->constrainDateColumn($departuresQuery, 'departure_date', $from, $to);

        $arrivalsCount = (clone $arrivalsQuery)->count();
        $departuresCount = (clone $departuresQuery)->count();

        $arrivalsList = $arrivalsQuery
            ->orderBy('arrival_date')
            ->limit(8)
            ->get()
            ->map(fn (HospitalityReservation $r) => $this->mapReservationRow($r))
            ->all();

        $departuresList = $departuresQuery
            ->orderBy('departure_date')
            ->limit(8)
            ->get()
            ->map(fn (HospitalityReservation $r) => $this->mapReservationRow($r))
            ->all();

        $openFolios = HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->count();

        $folioBalance = (float) HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->sum('balance');

        $openFoliosList = HospitalityFolio::query()
            ->with('room:id,room_number')
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->orderByDesc('balance')
            ->limit(8)
            ->get()
            ->map(fn (HospitalityFolio $f) => [
                'id' => $f->id,
                'folio_number' => $f->folio_number,
                'guest_name' => $f->guest_name,
                'room_number' => $f->room?->room_number,
                'balance' => round((float) $f->balance, 2),
                'checked_in_at' => optional($f->checked_in_at)?->toDateString(),
            ])
            ->all();

        $fnbPaidQuery = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', HospitalityCheckService::PAID_STATUSES);
        $this->constrainDateColumn($fnbPaidQuery, 'closed_at', $from, $to);
        $fnbPeriod = $fnbPaidQuery
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total),0) as revenue, COALESCE(SUM(amount_paid),0) as collected')
            ->first();

        $openFnb = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', HospitalityCheckService::EDITABLE_STATUSES)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total),0) as amount, COALESCE(SUM(total - amount_paid),0) as balance')
            ->first();

        $openChecksList = HospitalityCheck::query()
            ->with('outlet:id,name,code')
            ->where('organization_id', $org->id)
            ->whereIn('status', HospitalityCheckService::EDITABLE_STATUSES)
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn (HospitalityCheck $c) => [
                'id' => $c->id,
                'check_number' => $c->check_number,
                'guest_name' => $c->guest_name,
                'outlet_name' => $c->outlet?->name ?? 'Outlet',
                'status' => $c->status,
                'total' => round((float) $c->total, 2),
                'balance' => round(max(0, (float) $c->total - (float) $c->amount_paid), 2),
            ])
            ->all();

        $fnbByOutletQuery = DB::table('hospitality_checks as c')
            ->leftJoin('hospitality_outlets as o', 'o.id', '=', 'c.outlet_id')
            ->where('c.organization_id', $org->id)
            ->whereIn('c.status', ['paid', 'settled', 'partially_paid']);
        $this->constrainRawDate(
            $fnbByOutletQuery,
            'COALESCE(c.closed_at, c.updated_at)',
            $from,
            $to,
        );
        $fnbByOutlet = $fnbByOutletQuery
            ->groupBy('o.id', 'o.code', 'o.name')
            ->orderByDesc(DB::raw('SUM(c.total)'))
            ->selectRaw('COALESCE(o.code, \'MAIN\') as outlet_code')
            ->selectRaw('COALESCE(o.name, \'Main outlet\') as outlet_name')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw('ROUND(SUM(c.total), 2) as gross_sales')
            ->selectRaw('ROUND(SUM(c.amount_paid), 2) as collected')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'outlet_code' => $r->outlet_code,
                'outlet_name' => $r->outlet_name,
                'checks' => (int) $r->checks,
                'gross_sales' => (float) $r->gross_sales,
                'collected' => (float) $r->collected,
            ])
            ->all();

        $roomRevenue = 0.0;
        $roomNights = 0;
        if (Schema::hasTable('hospitality_folio_charges')) {
            $roomChargeQuery = DB::table('hospitality_folio_charges')
                ->where('organization_id', $org->id)
                ->where('charge_type', 'room');
            $this->constrainRawDate(
                $roomChargeQuery,
                'COALESCE(business_date, posted_at, created_at)',
                $from,
                $to,
            );
            $roomNights = (int) (clone $roomChargeQuery)->count();
            $roomRevenue = (float) (clone $roomChargeQuery)->sum('amount');
        }
        // Live open-folio proxy only when viewing today and night audit has not posted yet.
        if ($roomNights <= 0 && $isToday) {
            $roomNights = (int) HospitalityFolio::query()
                ->where('organization_id', $org->id)
                ->where('status', 'open')
                ->whereNotNull('room_id')
                ->count();
        }

        $periodDays = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $adr = $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : 0.0;
        $revpar = $sellable > 0
            ? round($roomRevenue / ($sellable * $periodDays), 2)
            : 0.0;
        $occupancyPct = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100, 1) : 0.0;

        $nightAudit = null;
        if (Schema::hasTable('hospitality_night_audits')) {
            $last = HospitalityNightAudit::query()
                ->where('organization_id', $org->id)
                ->orderByDesc('business_date')
                ->first();
            $priorComplete = HospitalityNightAudit::query()
                ->where('organization_id', $org->id)
                ->whereDate('business_date', $priorDay)
                ->exists();
            $nightAudit = [
                'yesterday_date' => $priorDay,
                'yesterday_complete' => $priorComplete,
                'last_business_date' => optional($last?->business_date)?->toDateString(),
                'last_rooms_posted' => $last ? (int) $last->rooms_posted : null,
                'last_amount_posted' => $last ? round((float) $last->amount_posted, 2) : null,
            ];
        }

        $inHouse = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->where('status', 'checked_in')
            ->count();

        $fnbRevenue = round((float) ($fnbPeriod->revenue ?? 0), 2);
        $fnbCollected = round((float) ($fnbPeriod->collected ?? 0), 2);

        return [
            'as_of' => Carbon::now()->toIso8601String(),
            'from_date' => $from,
            'to_date' => $to,
            'business_date' => $to,
            'period_days' => $periodDays,
            'rooms' => [
                'total' => $totalRooms,
                'sellable' => $sellable,
                'occupied' => $occupied,
                'vacant' => $vacant,
                'dirty' => $dirty,
                'clean' => $clean,
                'ooo' => $ooo,
                'occupancy_pct' => $occupancyPct,
            ],
            'kpis' => [
                'occupancy_pct' => $occupancyPct,
                'adr' => $adr,
                'revpar' => $revpar,
                'room_revenue' => round($roomRevenue, 2),
                'room_nights' => $roomNights,
                'fnb_revenue' => $fnbRevenue,
                'fnb_collected' => $fnbCollected,
                'total_revenue' => round($roomRevenue + $fnbCollected, 2),
                'in_house' => $inHouse,
            ],
            'arrivals_today' => $arrivalsCount,
            'departures_today' => $departuresCount,
            'arrivals' => $arrivalsList,
            'departures' => $departuresList,
            'open_folios' => $openFolios,
            'open_folio_balance' => round($folioBalance, 2),
            'open_folios_list' => $openFoliosList,
            'fnb_today' => [
                'checks' => (int) ($fnbPeriod->cnt ?? 0),
                'revenue' => $fnbRevenue,
                'collected' => $fnbCollected,
                'open_checks' => (int) ($openFnb->cnt ?? 0),
                'open_amount' => round((float) ($openFnb->amount ?? 0), 2),
                'open_balance' => round((float) ($openFnb->balance ?? 0), 2),
            ],
            'fnb_by_outlet' => $fnbByOutlet,
            'open_checks' => $openChecksList,
            'night_audit' => $nightAudit,
        ];
    }

    /**
     * @param  array{from_date?: string|null, to_date?: string|null}  $filters
     * @return array{0: string, 1: string}
     */
    protected function resolveDateRange(array $filters): array
    {
        $today = now()->toDateString();
        $fromRaw = trim((string) ($filters['from_date'] ?? ''));
        $toRaw = trim((string) ($filters['to_date'] ?? ''));

        try {
            $from = $fromRaw !== '' ? Carbon::parse($fromRaw)->toDateString() : $today;
        } catch (\Throwable) {
            $from = $today;
        }

        try {
            $to = $toRaw !== '' ? Carbon::parse($toRaw)->toDateString() : ($fromRaw !== '' ? $from : $today);
        } catch (\Throwable) {
            $to = $from;
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // Cap range to 93 days so overview queries stay snappy.
        $maxTo = Carbon::parse($from)->addDays(92)->toDateString();
        if ($to > $maxTo) {
            $to = $maxTo;
        }

        return [$from, $to];
    }

    protected function constrainDateColumn(Builder $query, string $column, string $from, string $to): void
    {
        if ($from === $to) {
            $query->whereDate($column, $from);

            return;
        }

        $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }

    protected function constrainRawDate($query, string $expression, string $from, string $to): void
    {
        if ($from === $to) {
            $query->whereDate(DB::raw($expression), $from);

            return;
        }

        $query->whereBetween(DB::raw('DATE('.$expression.')'), [$from, $to]);
    }

    /** @return array<string, mixed> */
    protected function mapReservationRow(HospitalityReservation $r): array
    {
        return [
            'id' => $r->id,
            'confirmation_code' => $r->confirmation_code,
            'guest_name' => $r->guest_name,
            'room_type' => $r->roomType?->name,
            'room_number' => $r->room?->room_number,
            'arrival_date' => optional($r->arrival_date)?->toDateString(),
            'departure_date' => optional($r->departure_date)?->toDateString(),
            'status' => $r->status,
        ];
    }
}
