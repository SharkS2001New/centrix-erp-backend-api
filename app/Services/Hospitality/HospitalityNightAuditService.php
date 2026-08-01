<?php

namespace App\Services\Hospitality;

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

            $audit = HospitalityNightAudit::create([
                'organization_id' => $org->id,
                'business_date' => $date,
                'ran_by' => $user->id,
                'rooms_posted' => $posted,
                'amount_posted' => round($amount, 2),
                'details' => $details,
            ]);

            return [
                'business_date' => $date,
                'rooms_posted' => (int) $audit->rooms_posted,
                'amount_posted' => (float) $audit->amount_posted,
                'details' => $details,
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
            ->with(['room.roomType'])
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->whereNotNull('room_id')
            ->get();

        $out = [];
        foreach ($folios as $folio) {
            $already = HospitalityFolioCharge::query()
                ->where('folio_id', $folio->id)
                ->where('charge_type', 'room')
                ->whereDate('business_date', $date)
                ->exists();
            if ($already) {
                continue;
            }

            $amount = $this->nightlyRate($org, $folio);
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

    protected function nightlyRate(Organization $org, HospitalityFolio $folio): float
    {
        $reservation = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->where('folio_id', $folio->id)
            ->first();

        if ($reservation?->rate_plan_id) {
            $plan = HospitalityRatePlan::query()->find($reservation->rate_plan_id);
            if ($plan) {
                return round((float) $plan->amount, 2);
            }
        }

        $roomTypeId = $folio->room?->room_type_id;
        if ($roomTypeId) {
            $defaultPlan = HospitalityRatePlan::query()
                ->where('organization_id', $org->id)
                ->where('room_type_id', $roomTypeId)
                ->where('is_default', true)
                ->where('is_active', true)
                ->first();
            if ($defaultPlan) {
                return round((float) $defaultPlan->amount, 2);
            }
        }

        return round((float) ($folio->room?->roomType?->base_rate ?? 0), 2);
    }
}
