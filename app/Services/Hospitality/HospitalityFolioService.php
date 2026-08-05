<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityFolio;
use App\Models\HospitalityFolioCharge;
use App\Models\HospitalityFolioPayment;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HospitalityFolioService
{
    public function list(Organization $org, array $filters = []): array
    {
        $query = HospitalityFolio::query()
            ->with(['room:id,room_number,floor,status'])
            ->where('organization_id', $org->id)
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($inner) use ($q) {
                $inner->where('folio_number', 'like', "%{$q}%")
                    ->orWhere('guest_name', 'like', "%{$q}%")
                    ->orWhere('guest_phone', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);

        return $query->paginate($perPage)->through(fn (HospitalityFolio $f) => $this->toArray($f))->toArray();
    }

    public function find(Organization $org, int $folioId): HospitalityFolio
    {
        return HospitalityFolio::query()
            ->with([
                'room.roomType',
                'charges' => fn ($q) => $q->orderByDesc('id'),
                'payments' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->where('organization_id', $org->id)
            ->where('id', $folioId)
            ->firstOrFail();
    }

    public function open(
        Organization $org,
        User $user,
        string $guestName,
        ?string $guestPhone,
        ?int $roomId,
        ?int $branchId = null,
    ): HospitalityFolio {
        return DB::transaction(function () use ($org, $user, $guestName, $guestPhone, $roomId, $branchId) {
            $room = null;
            if ($roomId) {
                $room = HospitalityRoom::query()
                    ->where('organization_id', $org->id)
                    ->where('id', $roomId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (in_array($room->status, ['occupied', 'ooo'], true)) {
                    throw ValidationException::withMessages([
                        'room_id' => ["Room {$room->room_number} is not available ({$room->status})."],
                    ]);
                }
            }

            $folio = HospitalityFolio::create([
                'organization_id' => $org->id,
                'branch_id' => $branchId ?? $user->branch_id,
                'room_id' => $room?->id,
                'folio_number' => $this->nextFolioNumber($org),
                'guest_name' => trim($guestName),
                'guest_phone' => $guestPhone ? trim($guestPhone) : null,
                'status' => 'open',
                'checked_in_at' => now(),
                'opened_by' => $user->id,
                'balance' => 0,
            ]);

            if ($room) {
                $room->update(['status' => 'occupied']);
            }

            return $folio->fresh(['room.roomType']);
        });
    }

    public function addCharge(
        HospitalityFolio $folio,
        User $user,
        string $chargeType,
        string $description,
        float $amount,
        float $vatAmount = 0,
        ?int $checkId = null,
        mixed $businessDate = null,
    ): HospitalityFolio {
        $this->assertOpen($folio);
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Charge amount must be greater than zero.']]);
        }
        $type = strtolower(trim($chargeType));
        if (! in_array($type, ['room', 'fnb', 'minibar', 'laundry', 'other'], true)) {
            throw ValidationException::withMessages(['charge_type' => ['Invalid charge type.']]);
        }

        HospitalityFolioCharge::create([
            'organization_id' => $folio->organization_id,
            'folio_id' => $folio->id,
            'check_id' => $checkId,
            'charge_type' => $type,
            'description' => trim($description),
            'amount' => $amount,
            'vat_amount' => round($vatAmount, 2),
            'business_date' => $businessDate,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        return $this->recomputeBalance($folio->fresh());
    }

    public function addPayment(
        HospitalityFolio $folio,
        User $user,
        string $methodCode,
        float $amount,
        ?string $reference = null,
    ): HospitalityFolio {
        $this->assertOpen($folio);
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Payment amount must be greater than zero.']]);
        }

        HospitalityFolioPayment::create([
            'organization_id' => $folio->organization_id,
            'folio_id' => $folio->id,
            'method_code' => strtoupper(trim($methodCode)),
            'amount' => $amount,
            'reference' => $reference,
            'received_by' => $user->id,
        ]);

        return $this->recomputeBalance($folio->fresh());
    }

    public function void(HospitalityFolio $folio): HospitalityFolio
    {
        $this->assertOpen($folio);
        if ((float) $folio->balance !== 0.0) {
            throw ValidationException::withMessages([
                'folio' => ['Clear or zero the balance before voiding, or check out instead.'],
            ]);
        }
        $folio->update(['status' => 'void', 'checked_out_at' => now()]);
        if ($folio->room_id) {
            HospitalityRoom::query()->where('id', $folio->room_id)->update(['status' => 'dirty']);
        }

        return $folio->fresh(['room']);
    }

    public function recomputeBalance(HospitalityFolio $folio): HospitalityFolio
    {
        $charges = (float) HospitalityFolioCharge::query()->where('folio_id', $folio->id)->sum('amount');
        $payments = (float) HospitalityFolioPayment::query()->where('folio_id', $folio->id)->sum('amount');
        $folio->update(['balance' => round($charges - $payments, 2)]);

        return $folio->fresh([
            'room.roomType',
            'charges' => fn ($q) => $q->orderByDesc('id'),
            'payments' => fn ($q) => $q->orderByDesc('id'),
        ]);
    }

    public function nextFolioNumber(Organization $org): string
    {
        $n = HospitalityFolio::query()->where('organization_id', $org->id)->count() + 1;

        return 'F'.now()->format('ymd').'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function toArray(HospitalityFolio $folio, bool $detail = false): array
    {
        $row = [
            'id' => $folio->id,
            'kind' => 'folio',
            'folio_number' => $folio->folio_number,
            'guest_name' => $folio->guest_name,
            'guest_phone' => $folio->guest_phone,
            'status' => $folio->status,
            'room_id' => $folio->room_id,
            'room_number' => $folio->room?->room_number,
            'room_status' => $folio->room?->status,
            'balance' => (float) $folio->balance,
            'checked_in_at' => optional($folio->checked_in_at)?->toIso8601String(),
            'checked_out_at' => optional($folio->checked_out_at)?->toIso8601String(),
        ];

        if ($detail) {
            $row['charges'] = $folio->charges->map(fn (HospitalityFolioCharge $c) => [
                'id' => $c->id,
                'charge_type' => $c->charge_type,
                'description' => $c->description,
                'amount' => (float) $c->amount,
                'vat_amount' => (float) $c->vat_amount,
                'business_date' => optional($c->business_date)?->toDateString(),
                'check_id' => $c->check_id,
                'posted_at' => optional($c->posted_at)?->toIso8601String(),
            ])->values()->all();
            $row['payments'] = $folio->payments->map(fn (HospitalityFolioPayment $p) => [
                'id' => $p->id,
                'method_code' => $p->method_code,
                'amount' => (float) $p->amount,
                'reference' => $p->reference,
                'created_at' => optional($p->created_at)?->toIso8601String(),
            ])->values()->all();
            $row['room_type_name'] = $folio->room?->roomType?->name;
            $row['room_type_base_rate'] = (float) ($folio->room?->roomType?->base_rate ?? 0);
        }

        return $row;
    }

    protected function assertOpen(HospitalityFolio $folio): void
    {
        if ($folio->status !== 'open') {
            throw ValidationException::withMessages(['folio' => ['Folio is not open.']]);
        }
    }
}
