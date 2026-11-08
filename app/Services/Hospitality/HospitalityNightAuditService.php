<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityFolio;
use App\Models\HospitalityFolioCharge;
use App\Models\HospitalityNightAudit;
use App\Models\HospitalityRatePlan;
use App\Models\HospitalityReservation;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HospitalityNightAuditService
{
    public function __construct(
        protected HospitalityFolioService $folios,
        protected HospitalityReportService $reports,
    ) {}

    public function preview(Organization $org, ?string $businessDate = null): array
    {
        $date = $businessDate ? Carbon::parse($businessDate)->toDateString() : now()->toDateString();
        $existing = HospitalityNightAudit::query()
            ->where('organization_id', $org->id)
            ->whereDate('business_date', $date)
            ->first();

        $candidates = $this->candidates($org, $date);

        return [
            'business_date' => $date,
            'already_run' => (bool) $existing,
            'last_run' => $existing ? [
                'rooms_posted' => (int) $existing->rooms_posted,
                'amount_posted' => (float) $existing->amount_posted,
                'ran_at' => optional($existing->created_at)?->toIso8601String(),
            ] : null,
            'candidates' => $candidates,
            'total_amount' => round(array_sum(array_column($candidates, 'amount')), 2),
            'rooms_count' => count($candidates),
            // Cheap snapshot only — full manager flash runs on post / reports hub.
            'snapshot' => $this->previewSnapshot($org, $date),
        ];
    }

    /**
     * Light board counts for preview UI (no occupancy/FnB/EOD flash queries).
     *
     * @return array<string, int|float|string>
     */
    protected function previewSnapshot(Organization $org, string $date): array
    {
        return [
            'business_date' => $date,
            'arrivals' => HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->whereDate('arrival_date', $date)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->count(),
            'departures' => HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->whereDate('departure_date', $date)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->count(),
            'open_folios' => HospitalityFolio::query()
                ->where('organization_id', $org->id)
                ->where('status', 'open')
                ->count(),
            'open_folio_balance' => round((float) HospitalityFolio::query()
                ->where('organization_id', $org->id)
                ->where('status', 'open')
                ->sum('balance'), 2),
            'open_fnb_checks' => HospitalityCheck::query()
                ->where('organization_id', $org->id)
                ->whereIn('status', ['unpaid', 'partially_paid', 'held', 'open'])
                ->count(),
        ];
    }

    public function run(Organization $org, User $user, ?string $businessDate = null): array
    {
        $date = $businessDate ? Carbon::parse($businessDate)->toDateString() : now()->toDateString();

        return DB::transaction(function () use ($org, $user, $date) {
            $exists = HospitalityNightAudit::query()
                ->where('organization_id', $org->id)
                ->whereDate('business_date', $date)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'business_date' => ["Night audit already ran for {$date}."],
                ]);
            }

            $candidates = $this->candidates($org, $date);
            $posted = 0;
            $amount = 0.0;
            $details = [];

            foreach ($candidates as $row) {
                $folio = HospitalityFolio::query()->find($row['folio_id']);
                if (! $folio || $folio->status !== 'open') {
                    continue;
                }
                $this->folios->addCharge(
                    $folio,
                    $user,
                    'room',
                    $row['description'],
                    (float) $row['amount'],
                    0,
                    null,
                    $date,
                );
                $posted++;
                $amount += (float) $row['amount'];
                $details[] = $row;
            }

            $flash = $this->reports->run($org, 'hospitality-manager-flash', $date, $date);

            $audit = HospitalityNightAudit::create([
                'organization_id' => $org->id,
                'business_date' => $date,
                'ran_by' => $user->id,
                'rooms_posted' => $posted,
                'amount_posted' => round($amount, 2),
                'details' => [
                    'posted' => $details,
                    'manager_flash' => $flash,
                ],
            ]);

            return [
                'business_date' => $date,
                'rooms_posted' => (int) $audit->rooms_posted,
                'amount_posted' => (float) $audit->amount_posted,
                'details' => $details,
                'manager_flash' => $flash,
            ];
        });
    }

    public function history(Organization $org, int $limit = 20): array
    {
        return HospitalityNightAudit::query()
            ->where('organization_id', $org->id)
            ->orderByDesc('business_date')
            ->limit($limit)
            ->get()
            ->map(fn (HospitalityNightAudit $a) => [
                'id' => $a->id,
                'business_date' => optional($a->business_date)?->toDateString(),
                'rooms_posted' => (int) $a->rooms_posted,
                'amount_posted' => (float) $a->amount_posted,
                'ran_at' => optional($a->created_at)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function candidates(Organization $org, string $date): array
    {
        $folios = HospitalityFolio::query()
            ->with(['room.roomType:id,base_rate,name,code'])
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->whereNotNull('room_id')
            ->get();

        if ($folios->isEmpty()) {
            return [];
        }

        $folioIds = $folios->pluck('id')->map(fn ($id) => (int) $id)->all();

        $alreadyPosted = HospitalityFolioCharge::query()
            ->whereIn('folio_id', $folioIds)
            ->where('charge_type', 'room')
            ->whereDate('business_date', $date)
            ->pluck('folio_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->flip()
            ->all();

        $reservations = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->whereIn('folio_id', $folioIds)
            ->get(['folio_id', 'rate_plan_id'])
            ->keyBy(fn (HospitalityReservation $r) => (int) $r->folio_id);

        $ratePlanIds = $reservations
            ->pluck('rate_plan_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $plansById = $ratePlanIds === []
            ? collect()
            : HospitalityRatePlan::query()
                ->whereIn('id', $ratePlanIds)
                ->get(['id', 'amount'])
                ->keyBy('id');

        $roomTypeIds = $folios
            ->map(fn (HospitalityFolio $f) => $f->room?->room_type_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $defaultPlansByType = $roomTypeIds === []
            ? []
            : HospitalityRatePlan::query()
                ->where('organization_id', $org->id)
                ->whereIn('room_type_id', $roomTypeIds)
                ->where('is_default', true)
                ->where('is_active', true)
                ->get(['room_type_id', 'amount'])
                ->mapWithKeys(fn (HospitalityRatePlan $p) => [
                    (int) $p->room_type_id => round((float) $p->amount, 2),
                ])
                ->all();

        $out = [];
        foreach ($folios as $folio) {
            $folioId = (int) $folio->id;
            if (isset($alreadyPosted[$folioId])) {
                continue;
            }

            $amount = 0.0;
            $reservation = $reservations->get($folioId);
            if ($reservation?->rate_plan_id) {
                $plan = $plansById->get((int) $reservation->rate_plan_id);
                if ($plan) {
                    $amount = round((float) $plan->amount, 2);
                }
            }

            if ($amount <= 0) {
                $roomTypeId = $folio->room?->room_type_id ? (int) $folio->room->room_type_id : null;
                if ($roomTypeId && array_key_exists($roomTypeId, $defaultPlansByType)) {
                    $amount = $defaultPlansByType[$roomTypeId];
                } else {
                    $amount = round((float) ($folio->room?->roomType?->base_rate ?? 0), 2);
                }
            }

            if ($amount <= 0) {
                continue;
            }

            $out[] = [
                'folio_id' => $folio->id,
                'folio_number' => $folio->folio_number,
                'guest_name' => $folio->guest_name,
                'room_number' => $folio->room?->room_number,
                'amount' => $amount,
                'description' => "Room night {$date} — {$folio->room?->room_number}",
            ];
        }

        return $out;
    }
}
