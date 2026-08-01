<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityFolio;
use App\Models\HospitalityReservation;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
}
