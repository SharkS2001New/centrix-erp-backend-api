<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityFolio;
use App\Models\HospitalityReservation;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use Carbon\Carbon;

class HospitalityDashboardService
{
    public function summary(Organization $org): array
    {
        $today = now()->toDateString();
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

        $arrivals = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->whereDate('arrival_date', $today)
            ->where('status', 'booked')
            ->count();

        $departures = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->whereDate('departure_date', $today)
            ->whereIn('status', ['checked_in', 'booked'])
            ->count();

        $openFolios = HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->count();

        $folioBalance = (float) HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->sum('balance');

        $fnbToday = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', HospitalityCheckService::PAID_STATUSES)
            ->whereDate('closed_at', $today)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total),0) as revenue')
            ->first();

        $openFnb = HospitalityCheck::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', HospitalityCheckService::EDITABLE_STATUSES)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total),0) as amount')
            ->first();

        return [
            'as_of' => Carbon::now()->toIso8601String(),
            'rooms' => [
                'total' => $totalRooms,
                'occupied' => $occupied,
                'vacant' => $vacant,
                'dirty' => $dirty,
                'clean' => $clean,
                'ooo' => $ooo,
                'occupancy_pct' => $totalRooms > 0 ? round(($occupied / $totalRooms) * 100, 1) : 0,
            ],
            'arrivals_today' => $arrivals,
            'departures_today' => $departures,
            'open_folios' => $openFolios,
            'open_folio_balance' => round($folioBalance, 2),
            'fnb_today' => [
                'checks' => (int) ($fnbToday->cnt ?? 0),
                'revenue' => round((float) ($fnbToday->revenue ?? 0), 2),
                'open_checks' => (int) ($openFnb->cnt ?? 0),
                'open_amount' => round((float) ($openFnb->amount ?? 0), 2),
            ],
        ];
    }
}
