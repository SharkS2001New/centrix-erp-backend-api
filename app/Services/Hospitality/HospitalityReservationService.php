<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityRatePlan;
use App\Models\HospitalityReservation;
use App\Models\HospitalityRoom;
use App\Models\HospitalityRoomType;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class HospitalityReservationService
{
    public function list(Organization $org, array $filters = []): array
    {
        $query = HospitalityReservation::query()
            ->with(['room:id,room_number', 'roomType:id,code,name,base_rate', 'ratePlan:id,code,name,amount'])
            ->where('organization_id', $org->id)
            ->orderBy('arrival_date')
            ->orderBy('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('arrival_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('arrival_date', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($inner) use ($q) {
                $inner->where('guest_name', 'like', "%{$q}%")
                    ->orWhere('confirmation_code', 'like', "%{$q}%")
                    ->orWhere('guest_phone', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);

        return $query->paginate($perPage)->through(fn (HospitalityReservation $r) => $this->toArray($r))->toArray();
    }

    public function find(Organization $org, int $id): HospitalityReservation
    {
        return HospitalityReservation::query()
            ->with(['room', 'roomType', 'ratePlan', 'folio'])
            ->where('organization_id', $org->id)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Organization $org, array $data, ?int $branchId = null): HospitalityReservation
    {
        $payload = $this->validatedPayload($org, $data);
        $this->assertNoRoomOverlap($org, $payload['room_id'], $payload['arrival_date'], $payload['departure_date']);

        $reservation = HospitalityReservation::create([
            'organization_id' => $org->id,
            'branch_id' => $branchId,
            'room_type_id' => $payload['room_type_id'],
            'room_id' => $payload['room_id'],
            'rate_plan_id' => $payload['rate_plan_id'],
            'confirmation_code' => $this->nextConfirmationCode($org),
            'guest_name' => $payload['guest_name'],
            'guest_phone' => $payload['guest_phone'],
            'arrival_date' => $payload['arrival_date'],
            'departure_date' => $payload['departure_date'],
            'status' => 'booked',
            'deposit_amount' => $payload['deposit_amount'],
            'adults' => $payload['adults'],
            'notes' => $payload['notes'],
        ]);

        return $reservation->fresh(['room', 'roomType', 'ratePlan']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Organization $org, int $id, array $data): HospitalityReservation
    {
        $reservation = $this->find($org, $id);
        if (! in_array($reservation->status, ['booked'], true)) {
            throw ValidationException::withMessages([
                'reservation' => ['Only booked reservations can be edited.'],
            ]);
        }

        $payload = $this->validatedPayload($org, array_merge([
            'room_type_id' => $reservation->room_type_id,
            'room_id' => $reservation->room_id,
            'rate_plan_id' => $reservation->rate_plan_id,
            'guest_name' => $reservation->guest_name,
            'guest_phone' => $reservation->guest_phone,
            'arrival_date' => $reservation->arrival_date?->toDateString(),
            'departure_date' => $reservation->departure_date?->toDateString(),
            'deposit_amount' => $reservation->deposit_amount,
            'adults' => $reservation->adults,
            'notes' => $reservation->notes,
        ], $data));

        $this->assertNoRoomOverlap(
            $org,
            $payload['room_id'],
            $payload['arrival_date'],
            $payload['departure_date'],
            $reservation->id,
        );

        $reservation->update([
            'room_type_id' => $payload['room_type_id'],
            'room_id' => $payload['room_id'],
            'rate_plan_id' => $payload['rate_plan_id'],
            'guest_name' => $payload['guest_name'],
            'guest_phone' => $payload['guest_phone'],
            'arrival_date' => $payload['arrival_date'],
            'departure_date' => $payload['departure_date'],
            'deposit_amount' => $payload['deposit_amount'],
            'adults' => $payload['adults'],
            'notes' => $payload['notes'],
        ]);

        return $reservation->fresh(['room', 'roomType', 'ratePlan']);
    }

    public function setStatus(Organization $org, int $id, string $status): HospitalityReservation
    {
        $reservation = $this->find($org, $id);
        if (! in_array($status, ['cancelled', 'no_show'], true)) {
            throw ValidationException::withMessages(['status' => ['Use cancelled or no_show.']]);
        }
        if ($reservation->status !== 'booked') {
            throw ValidationException::withMessages([
                'reservation' => ['Only booked reservations can be cancelled or marked no-show.'],
            ]);
        }
        $reservation->update(['status' => $status]);

        return $reservation->fresh(['room', 'roomType', 'ratePlan']);
    }

    public function toArray(HospitalityReservation $r): array
    {
        return [
            'id' => $r->id,
            'confirmation_code' => $r->confirmation_code,
            'guest_name' => $r->guest_name,
            'guest_phone' => $r->guest_phone,
            'arrival_date' => optional($r->arrival_date)?->toDateString(),
            'departure_date' => optional($r->departure_date)?->toDateString(),
            'status' => $r->status,
            'room_type_id' => $r->room_type_id,
            'room_type_name' => $r->roomType?->name,
            'room_id' => $r->room_id,
            'room_number' => $r->room?->room_number,
            'rate_plan_id' => $r->rate_plan_id,
            'rate_plan_name' => $r->ratePlan?->name,
            'rate_amount' => (float) ($r->ratePlan?->amount ?? $r->roomType?->base_rate ?? 0),
            'folio_id' => $r->folio_id,
            'deposit_amount' => (float) $r->deposit_amount,
            'adults' => (int) ($r->adults ?? 1),
            'notes' => $r->notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function validatedPayload(Organization $org, array $data): array
    {
        $guestName = trim((string) ($data['guest_name'] ?? ''));
        if ($guestName === '') {
            throw ValidationException::withMessages(['guest_name' => ['Guest name is required.']]);
        }

        $arrival = Carbon::parse((string) ($data['arrival_date'] ?? ''))->startOfDay();
        $departure = Carbon::parse((string) ($data['departure_date'] ?? ''))->startOfDay();
        if ($departure->lte($arrival)) {
            throw ValidationException::withMessages([
                'departure_date' => ['Departure must be after arrival.'],
            ]);
        }

        $roomTypeId = isset($data['room_type_id']) ? (int) $data['room_type_id'] : null;
        if (! $roomTypeId) {
            throw ValidationException::withMessages(['room_type_id' => ['Select a room type.']]);
        }
        $type = HospitalityRoomType::query()
            ->where('organization_id', $org->id)
            ->where('id', $roomTypeId)
            ->first();
        if (! $type) {
            throw ValidationException::withMessages(['room_type_id' => ['Room type not found.']]);
        }

        $roomId = isset($data['room_id']) && $data['room_id'] !== '' && $data['room_id'] !== null
            ? (int) $data['room_id']
            : null;
        if ($roomId) {
            $room = HospitalityRoom::query()
                ->where('organization_id', $org->id)
                ->where('id', $roomId)
                ->first();
            if (! $room || (int) $room->room_type_id !== $roomTypeId) {
                throw ValidationException::withMessages(['room_id' => ['Room not found for this type.']]);
            }
        }

        $ratePlanId = isset($data['rate_plan_id']) && $data['rate_plan_id'] !== '' && $data['rate_plan_id'] !== null
            ? (int) $data['rate_plan_id']
            : null;
        if ($ratePlanId) {
            $plan = HospitalityRatePlan::query()
                ->where('organization_id', $org->id)
                ->where('id', $ratePlanId)
                ->where('room_type_id', $roomTypeId)
                ->first();
            if (! $plan) {
                throw ValidationException::withMessages(['rate_plan_id' => ['Rate plan not found for this room type.']]);
            }
        }

        return [
            'guest_name' => $guestName,
            'guest_phone' => isset($data['guest_phone']) ? (trim((string) $data['guest_phone']) ?: null) : null,
            'arrival_date' => $arrival->toDateString(),
            'departure_date' => $departure->toDateString(),
            'room_type_id' => $roomTypeId,
            'room_id' => $roomId,
            'rate_plan_id' => $ratePlanId,
            'deposit_amount' => round((float) ($data['deposit_amount'] ?? 0), 2),
            'adults' => max(1, (int) ($data['adults'] ?? 1)),
            'notes' => isset($data['notes']) ? (trim((string) $data['notes']) ?: null) : null,
        ];
    }

    protected function assertNoRoomOverlap(
        Organization $org,
        ?int $roomId,
        string $arrival,
        string $departure,
        ?int $exceptId = null,
    ): void {
        if (! $roomId) {
            return;
        }

        $overlap = HospitalityReservation::query()
            ->where('organization_id', $org->id)
            ->where('room_id', $roomId)
            ->whereIn('status', ['booked', 'checked_in'])
            ->where('arrival_date', '<', $departure)
            ->where('departure_date', '>', $arrival)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'room_id' => ['This room already has a reservation overlapping these dates.'],
            ]);
        }
    }

    protected function nextConfirmationCode(Organization $org): string
    {
        do {
            $code = 'H'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        } while (
            HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->where('confirmation_code', $code)
                ->exists()
        );

        return $code;
    }
}
