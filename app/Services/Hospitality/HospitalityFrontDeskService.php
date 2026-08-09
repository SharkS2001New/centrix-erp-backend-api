<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityFolio;
use App\Models\HospitalityReservation;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HospitalityFrontDeskService
{
    public function __construct(
        protected HospitalityFolioService $folios,
        protected HospitalityReservationService $reservations,
    ) {}

    public function usesFolios(Organization $org): bool
    {
        return HospitalityServices::enabled($org, 'folios');
    }

    public function arrivals(Organization $org, ?string $date = null): array
    {
        $day = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        $rows = HospitalityReservation::query()
            ->with(['room:id,room_number', 'roomType:id,code,name'])
            ->where('organization_id', $org->id)
            ->whereDate('arrival_date', $day)
            ->where('status', 'booked')
            ->orderBy('guest_name')
            ->get();

        return $rows->map(fn (HospitalityReservation $r) => $this->reservations->toArray($r))->all();
    }

    public function departures(Organization $org, ?string $date = null): array
    {
        $day = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        if ($this->usesFolios($org)) {
            $rows = HospitalityFolio::query()
                ->with(['room:id,room_number,expected_checkout_at'])
                ->where('organization_id', $org->id)
                ->where('status', 'open')
                ->whereHas('room')
                ->whereDate('checked_in_at', '<=', $day)
                ->get()
                ->filter(function (HospitalityFolio $f) use ($org, $day) {
                    $res = HospitalityReservation::query()
                        ->where('organization_id', $org->id)
                        ->where('folio_id', $f->id)
                        ->first();
                    if ($res && $res->departure_date?->toDateString() === $day) {
                        return true;
                    }
                    // Walk-ins / POS stays: due when room expected checkout falls on this day.
                    $checkout = $f->room?->expected_checkout_at;
                    if ($checkout && Carbon::parse($checkout)->toDateString() === $day) {
                        return true;
                    }

                    return false;
                });

            return $rows->map(fn (HospitalityFolio $f) => $this->folios->toArray($f))->values()->all();
        }

        $byReservation = HospitalityReservation::query()
            ->with(['room'])
            ->where('organization_id', $org->id)
            ->where('status', 'checked_in')
            ->whereDate('departure_date', $day)
            ->orderBy('guest_name')
            ->get();

        $seenRoomIds = [];
        $out = [];
        foreach ($byReservation as $r) {
            $room = $r->room;
            if ($room?->id) {
                $seenRoomIds[(int) $room->id] = true;
            }
            $out[] = $this->occupancyArray(
                $room,
                $r->guest_name,
                $r->guest_phone,
                $room?->checked_in_at,
            );
        }

        // Walk-in occupancy (no reservation) due today via expected_checkout_at.
        $walkIns = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('status', 'occupied')
            ->whereDate('expected_checkout_at', $day)
            ->orderBy('room_number')
            ->get();
        foreach ($walkIns as $room) {
            if (isset($seenRoomIds[(int) $room->id])) {
                continue;
            }
            $out[] = $this->occupancyArray(
                $room,
                $room->guest_name,
                $room->guest_phone,
                $room->checked_in_at,
            );
        }

        return $out;
    }

    public function inHouse(Organization $org): array
    {
        if ($this->usesFolios($org)) {
            $rows = HospitalityFolio::query()
                ->with(['room:id,room_number,status,floor'])
                ->where('organization_id', $org->id)
                ->where('status', 'open')
                ->orderByDesc('checked_in_at')
                ->get();

            return $rows->map(fn (HospitalityFolio $f) => $this->folios->toArray($f))->all();
        }

        $rows = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('status', 'occupied')
            ->orderByDesc('checked_in_at')
            ->get();

        return $rows->map(fn (HospitalityRoom $room) => $this->occupancyArray(
            $room,
            $room->guest_name,
            $room->guest_phone,
            $room->checked_in_at,
        ))->all();
    }

    /**
     * Check in from reservation or walk-in.
     * With folios on: opens a guest folio (pay later / room charge).
     * With folios off: marks the room occupied for pay-at-check-in hotels.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkIn(Organization $org, User $user, array $data): array
    {
        return DB::transaction(function () use ($org, $user, $data) {
            $reservationId = isset($data['reservation_id']) ? (int) $data['reservation_id'] : null;
            $reservation = null;
            if ($reservationId) {
                $reservation = HospitalityReservation::query()
                    ->where('organization_id', $org->id)
                    ->where('id', $reservationId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($reservation->status !== 'booked') {
                    throw ValidationException::withMessages([
                        'reservation_id' => ['Reservation is not available for check-in.'],
                    ]);
                }
            }

            $guestName = trim((string) ($data['guest_name'] ?? $reservation?->guest_name ?? ''));
            $guestPhone = isset($data['guest_phone'])
                ? trim((string) $data['guest_phone'])
                : $reservation?->guest_phone;
            $roomId = isset($data['room_id']) ? (int) $data['room_id'] : (int) ($reservation?->room_id ?? 0);
            if ($guestName === '') {
                throw ValidationException::withMessages(['guest_name' => ['Guest name is required.']]);
            }
            if (! $roomId) {
                throw ValidationException::withMessages(['room_id' => ['Assign a room to check in.']]);
            }

            if ($this->usesFolios($org)) {
                return $this->checkInWithFolio(
                    $org,
                    $user,
                    $guestName,
                    $guestPhone ?: null,
                    $roomId,
                    $reservation,
                    $data,
                );
            }

            return $this->checkInOccupancyOnly(
                $org,
                $user,
                $guestName,
                $guestPhone ?: null,
                $roomId,
                $reservation,
                $data,
            );
        });
    }

    public function checkOut(Organization $org, User $user, int $folioId, bool $allowBalance = false): array
    {
        if (! $this->usesFolios($org)) {
            throw ValidationException::withMessages([
                'service' => ['Guest folios are disabled. Check out the occupied room instead.'],
            ]);
        }

        return DB::transaction(function () use ($org, $user, $folioId, $allowBalance) {
            $folio = HospitalityFolio::query()
                ->where('organization_id', $org->id)
                ->where('id', $folioId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($folio->status !== 'open') {
                throw ValidationException::withMessages(['folio' => ['Folio is not open.']]);
            }

            $folio = $this->folios->recomputeBalance($folio);
            if (! $allowBalance && abs((float) $folio->balance) > 0.009) {
                throw ValidationException::withMessages([
                    'balance' => ['Folio balance must be zero before check-out. Collect payment on the folio first.'],
                ]);
            }

            $folio->update([
                'status' => 'checked_out',
                'checked_out_at' => now(),
            ]);

            if ($folio->room_id) {
                HospitalityRoom::query()->where('id', $folio->room_id)->update([
                    'status' => 'dirty',
                    'guest_name' => null,
                    'guest_phone' => null,
                    'checked_in_at' => null,
                    'expected_checkout_at' => null,
                    'sold_check_id' => null,
                ]);
            }

            HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->where('folio_id', $folio->id)
                ->where('status', 'checked_in')
                ->update(['status' => 'checked_out']);

            return [
                'folio' => $this->folios->toArray($folio->fresh(['room']), true),
            ];
        });
    }

    public function checkOutRoom(Organization $org, int $roomId): array
    {
        if ($this->usesFolios($org)) {
            throw ValidationException::withMessages([
                'service' => ['Guest folios are enabled. Check out via the open folio.'],
            ]);
        }

        return DB::transaction(function () use ($org, $roomId) {
            $room = HospitalityRoom::query()
                ->where('organization_id', $org->id)
                ->where('id', $roomId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($room->status !== 'occupied') {
                throw ValidationException::withMessages([
                    'room_id' => ['Room is not occupied.'],
                ]);
            }

            $snapshot = $this->occupancyArray(
                $room,
                $room->guest_name,
                $room->guest_phone,
                $room->checked_in_at,
            );

            HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->where('room_id', $room->id)
                ->where('status', 'checked_in')
                ->update(['status' => 'checked_out']);

            $room->update([
                'status' => 'dirty',
                'guest_name' => null,
                'guest_phone' => null,
                'checked_in_at' => null,
                'expected_checkout_at' => null,
                'sold_check_id' => null,
            ]);

            return ['occupancy' => $snapshot, 'room' => $room->fresh()];
        });
    }

    public function assignRoom(Organization $org, int $folioId, int $roomId): array
    {
        if (! $this->usesFolios($org)) {
            throw ValidationException::withMessages([
                'service' => ['Guest folios are disabled. Reassign the occupied room instead.'],
            ]);
        }

        return DB::transaction(function () use ($org, $folioId, $roomId) {
            $folio = HospitalityFolio::query()
                ->where('organization_id', $org->id)
                ->where('id', $folioId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($folio->status !== 'open') {
                throw ValidationException::withMessages(['folio' => ['Folio is not open.']]);
            }

            $room = $this->claimRoom($org, $roomId, $folio->room_id ? (int) $folio->room_id : null);

            $oldRoomId = $folio->room_id;
            $folio->update(['room_id' => $room->id]);
            $room->update([
                'status' => 'occupied',
                'guest_name' => $folio->guest_name,
                'guest_phone' => $folio->guest_phone,
                'checked_in_at' => $folio->checked_in_at ?? now(),
            ]);
            if ($oldRoomId && (int) $oldRoomId !== (int) $room->id) {
                HospitalityRoom::query()->where('id', $oldRoomId)->update([
                    'status' => 'dirty',
                    'guest_name' => null,
                    'guest_phone' => null,
                    'checked_in_at' => null,
                ]);
            }

            HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->where('folio_id', $folio->id)
                ->update(['room_id' => $room->id]);

            return ['folio' => $this->folios->toArray($folio->fresh(['room']), true)];
        });
    }

    public function reassignOccupiedRoom(Organization $org, int $fromRoomId, int $toRoomId): array
    {
        if ($this->usesFolios($org)) {
            throw ValidationException::withMessages([
                'service' => ['Guest folios are enabled. Reassign via the open folio.'],
            ]);
        }

        return DB::transaction(function () use ($org, $fromRoomId, $toRoomId) {
            if ($fromRoomId === $toRoomId) {
                throw ValidationException::withMessages([
                    'room_id' => ['Guest is already in that room.'],
                ]);
            }

            $from = HospitalityRoom::query()
                ->where('organization_id', $org->id)
                ->where('id', $fromRoomId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($from->status !== 'occupied') {
                throw ValidationException::withMessages([
                    'room_id' => ['Source room is not occupied.'],
                ]);
            }

            $to = $this->claimRoom($org, $toRoomId, $fromRoomId);
            $guestName = $from->guest_name;
            $guestPhone = $from->guest_phone;
            $checkedInAt = $from->checked_in_at ?? now();

              $to->update([
                'status' => 'occupied',
                'guest_name' => $guestName,
                'guest_phone' => $guestPhone,
                'checked_in_at' => $checkedInAt,
                'expected_checkout_at' => $from->expected_checkout_at,
                'sold_check_id' => $from->sold_check_id,
            ]);
            $from->update([
                'status' => 'dirty',
                'guest_name' => null,
                'guest_phone' => null,
                'checked_in_at' => null,
                'expected_checkout_at' => null,
                'sold_check_id' => null,
            ]);

            HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->where('room_id', $fromRoomId)
                ->where('status', 'checked_in')
                ->update(['room_id' => $to->id]);

            return [
                'occupancy' => $this->occupancyArray($to->fresh(), $guestName, $guestPhone, $checkedInAt),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{folio: array<string, mixed>, reservation: ?array<string, mixed>}
     */
    protected function checkInWithFolio(
        Organization $org,
        User $user,
        string $guestName,
        ?string $guestPhone,
        int $roomId,
        ?HospitalityReservation $reservation,
        array $data = [],
    ): array {
        $folio = $this->folios->open(
            $org,
            $user,
            $guestName,
            $guestPhone,
            $roomId,
            $user->branch_id ? (int) $user->branch_id : null,
        );

        $expectedCheckout = $this->resolveExpectedCheckoutAt($reservation, $data);

        HospitalityRoom::query()->where('id', $roomId)->update([
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'checked_in_at' => $folio->checked_in_at ?? now(),
            'expected_checkout_at' => $expectedCheckout,
            // PMS folio stay — not a prepaid Hotel POS room sale.
            'sold_check_id' => null,
        ]);

        if ($reservation) {
            $reservation->update([
                'status' => 'checked_in',
                'folio_id' => $folio->id,
                'room_id' => $roomId,
            ]);
            if ((float) $reservation->deposit_amount > 0) {
                $this->folios->addPayment(
                    $folio,
                    $user,
                    'DEPOSIT',
                    (float) $reservation->deposit_amount,
                    'Reservation deposit '.$reservation->confirmation_code,
                );
                $folio = $folio->fresh([
                    'room.roomType',
                    'charges',
                    'payments',
                ]);
            }
        } else {
            // Walk-in: create a thin checked-in reservation so Departures / night audit stay aligned.
            $reservation = $this->createWalkInReservation(
                $org,
                $user,
                $guestName,
                $guestPhone,
                $roomId,
                $folio->id,
                $expectedCheckout,
                $data,
            );
            $deposit = (float) ($data['deposit_amount'] ?? 0);
            if ($deposit > 0) {
                $this->folios->addPayment(
                    $folio,
                    $user,
                    'DEPOSIT',
                    $deposit,
                    'Walk-in deposit '.$reservation->confirmation_code,
                );
                $folio = $folio->fresh([
                    'room.roomType',
                    'charges',
                    'payments',
                ]);
            }
        }

        return [
            'folio' => $this->folios->toArray($folio->fresh(['room.roomType', 'charges', 'payments']), true),
            'occupancy' => null,
            'reservation' => $reservation
                ? $this->reservations->toArray($reservation->fresh(['room', 'roomType', 'ratePlan']))
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{folio: null, occupancy: array<string, mixed>, reservation: ?array<string, mixed>}
     */
    protected function checkInOccupancyOnly(
        Organization $org,
        User $user,
        string $guestName,
        ?string $guestPhone,
        int $roomId,
        ?HospitalityReservation $reservation,
        array $data = [],
    ): array {
        $room = $this->claimRoom($org, $roomId, null);
        $checkedInAt = now();
        $expectedCheckout = $this->resolveExpectedCheckoutAt($reservation, $data);
        $room->update([
            'status' => 'occupied',
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'checked_in_at' => $checkedInAt,
            'expected_checkout_at' => $expectedCheckout,
            'sold_check_id' => null,
        ]);

        if ($reservation) {
            $reservation->update([
                'status' => 'checked_in',
                'folio_id' => null,
                'room_id' => $roomId,
            ]);
        } else {
            $reservation = $this->createWalkInReservation(
                $org,
                $user,
                $guestName,
                $guestPhone,
                $roomId,
                null,
                $expectedCheckout,
                $data,
            );
        }

        return [
            'folio' => null,
            'occupancy' => $this->occupancyArray($room->fresh(), $guestName, $guestPhone, $checkedInAt),
            'reservation' => $reservation
                ? $this->reservations->toArray($reservation->fresh(['room', 'roomType', 'ratePlan']))
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveExpectedCheckoutAt(?HospitalityReservation $reservation, array $data): Carbon
    {
        if ($reservation?->departure_date) {
            return Carbon::parse($reservation->departure_date->toDateString())->setTime(10, 0);
        }
        if (! empty($data['departure_date'])) {
            return Carbon::parse((string) $data['departure_date'])->setTime(10, 0);
        }

        return now()->addDay()->setTime(10, 0);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createWalkInReservation(
        Organization $org,
        ?User $user,
        string $guestName,
        ?string $guestPhone,
        int $roomId,
        ?int $folioId,
        Carbon $expectedCheckout,
        array $data = [],
    ): HospitalityReservation {
        $room = HospitalityRoom::query()
            ->with('roomType')
            ->where('organization_id', $org->id)
            ->where('id', $roomId)
            ->firstOrFail();

        $arrival = now()->toDateString();
        $departure = $expectedCheckout->toDateString();
        if ($departure <= $arrival) {
            $departure = now()->addDay()->toDateString();
        }

        return HospitalityReservation::create([
            'organization_id' => $org->id,
            'branch_id' => $user?->branch_id ? (int) $user->branch_id : $room->branch_id,
            'room_type_id' => $room->room_type_id,
            'room_id' => $roomId,
            'rate_plan_id' => null,
            'folio_id' => $folioId,
            'confirmation_code' => 'W'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'status' => 'checked_in',
            'deposit_amount' => (float) ($data['deposit_amount'] ?? 0),
            'adults' => max(1, (int) ($data['adults'] ?? 1)),
            'notes' => trim((string) ($data['notes'] ?? 'Walk-in')),
        ])->fresh(['room', 'roomType', 'ratePlan']);
    }

    protected function claimRoom(Organization $org, int $roomId, ?int $allowRoomId): HospitalityRoom
    {
        $room = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->where('id', $roomId)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            in_array($room->status, ['occupied', 'ooo'], true)
            && ($allowRoomId === null || (int) $allowRoomId !== (int) $room->id)
        ) {
            throw ValidationException::withMessages([
                'room_id' => ["Room {$room->room_number} is not available ({$room->status})."],
            ]);
        }

        return $room;
    }

    /**
     * @return array<string, mixed>
     */
    protected function occupancyArray(
        ?HospitalityRoom $room,
        ?string $guestName,
        ?string $guestPhone,
        mixed $checkedInAt,
    ): array {
        return [
            'id' => $room?->id,
            'kind' => 'occupancy',
            'folio_number' => null,
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'room_id' => $room?->id,
            'room_number' => $room?->room_number,
            'balance' => 0,
            'status' => $room?->status,
            'checked_in_at' => $checkedInAt
                ? Carbon::parse($checkedInAt)->toIso8601String()
                : null,
        ];
    }
}
