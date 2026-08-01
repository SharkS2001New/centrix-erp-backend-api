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
        $totalRooms = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->count();
        $occupied = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->where('status', 'occupied')
            ->count();
        $dirty = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->where('status', 'dirty')
            ->count();

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
            ->whereIn('status', ['paid', 'settled'])
            ->whereDate('closed_at', $today)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total),0) as revenue')
            ->first();

        return [
            'as_of' => Carbon::now()->toIso8601String(),
            'rooms' => [
                'total' => $totalRooms,
                'occupied' => $occupied,
                'dirty' => $dirty,
                'occupancy_pct' => $totalRooms > 0 ? round(($occupied / $totalRooms) * 100, 1) : 0,
            ],
            'arrivals_today' => $arrivals,
            'departures_today' => $departures,
            'open_folios' => $openFolios,
            'open_folio_balance' => round($folioBalance, 2),
            'fnb_today' => [
                'checks' => (int) ($fnbToday->cnt ?? 0),
                'revenue' => round((float) ($fnbToday->revenue ?? 0), 2),
            ],
        ];
    }
}
