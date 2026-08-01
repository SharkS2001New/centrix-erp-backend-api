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

        $rows = HospitalityFolio::query()
            ->with(['room:id,room_number'])
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

                return $res && $res->departure_date?->toDateString() === $day;
            });

        return $rows->map(fn (HospitalityFolio $f) => $this->folios->toArray($f))->values()->all();
    }

    public function inHouse(Organization $org): array
    {
        $rows = HospitalityFolio::query()
            ->with(['room:id,room_number,status,floor'])
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->orderByDesc('checked_in_at')
            ->get();

        return $rows->map(fn (HospitalityFolio $f) => $this->folios->toArray($f))->all();
    }

    /**
     * Check in from reservation or walk-in.
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

            $folio = $this->folios->open(
                $org,
                $user,
                $guestName,
                $guestPhone ?: null,
                $roomId,
                $user->branch_id ? (int) $user->branch_id : null,
            );

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
            }

            return [
                'folio' => $this->folios->toArray($folio, true),
                'reservation' => $reservation
                    ? $this->reservations->toArray($reservation->fresh(['room', 'roomType', 'ratePlan']))
                    : null,
            ];
        });
    }

    public function checkOut(Organization $org, User $user, int $folioId, bool $allowBalance = false): array
    {
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
                HospitalityRoom::query()->where('id', $folio->room_id)->update(['status' => 'dirty']);
            }

            return [
                'folio' => $this->folios->toArray($folio->fresh(['room']), true),
            ];
        });
    }

    public function assignRoom(Organization $org, int $folioId, int $roomId): array
    {
        return DB::transaction(function () use ($org, $folioId, $roomId) {
            $folio = HospitalityFolio::query()
                ->where('organization_id', $org->id)
                ->where('id', $folioId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($folio->status !== 'open') {
                throw ValidationException::withMessages(['folio' => ['Folio is not open.']]);
            }

            $room = HospitalityRoom::query()
                ->where('organization_id', $org->id)
                ->where('id', $roomId)
                ->lockForUpdate()
                ->firstOrFail();
            if (in_array($room->status, ['occupied', 'ooo'], true) && (int) $folio->room_id !== (int) $room->id) {
                throw ValidationException::withMessages([
                    'room_id' => ["Room {$room->room_number} is not available."],
                ]);
            }

            $oldRoomId = $folio->room_id;
            $folio->update(['room_id' => $room->id]);
            $room->update(['status' => 'occupied']);
            if ($oldRoomId && (int) $oldRoomId !== (int) $room->id) {
                HospitalityRoom::query()->where('id', $oldRoomId)->update(['status' => 'dirty']);
            }

            HospitalityReservation::query()
                ->where('organization_id', $org->id)
                ->where('folio_id', $folio->id)
                ->update(['room_id' => $room->id]);

            return ['folio' => $this->folios->toArray($folio->fresh(['room']), true)];
        });
    }
}
