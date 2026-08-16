<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityFolio;
use App\Models\HospitalityRatePlan;
use App\Models\HospitalityRoom;
use App\Models\HospitalityRoomType;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Backoffice room inventory aligned with Hotel POS sell rules:
 * vacant/clean + nightly rate, occupancy from POS prepaid stays or PMS folios.
 */
class HospitalityRoomInventoryService
{
    public const HOUSEKEEPING_STATUSES = ['vacant', 'clean', 'dirty', 'ooo'];

    /**
     * @return list<string>
     */
    public static function expandRoomNumbers(string $start, int $count): array
    {
        $start = trim($start);
        $count = (int) $count;
        if ($start === '') {
            throw ValidationException::withMessages([
                'start_number' => ['Start room number is required.'],
            ]);
        }
        if ($count < 1 || $count > 50) {
            throw ValidationException::withMessages([
                'count' => ['Create between 1 and 50 rooms at a time.'],
            ]);
        }
        if (! preg_match('/^(.*?)(\d+)$/', $start, $m)) {
            throw ValidationException::withMessages([
                'start_number' => ['Start with a number (101) or a prefix plus digits (G01).'],
            ]);
        }

        $prefix = $m[1];
        $n = (int) $m[2];
        $pad = strlen($m[2]);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $prefix.str_pad((string) ($n + $i), $pad, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * @param  Collection<int, HospitalityRoom>  $rooms
     * @return list<array<string, mixed>>
     */
    public function presentMany(Organization $org, Collection $rooms): array
    {
        if ($rooms->isEmpty()) {
            return [];
        }

        $roomIds = $rooms->pluck('id')->map(fn ($id) => (int) $id)->all();
        $typeIds = $rooms->pluck('room_type_id')->filter()->unique()->values()->all();

        $openFolioByRoomId = HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('status', 'open')
            ->whereIn('room_id', $roomIds)
            ->get(['id', 'room_id', 'folio_number', 'guest_name'])
            ->keyBy(fn (HospitalityFolio $f) => (int) $f->room_id);

        $defaultRateByType = HospitalityRatePlan::query()
            ->where('organization_id', $org->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereIn('room_type_id', $typeIds ?: [0])
            ->get(['room_type_id', 'amount'])
            ->keyBy(fn (HospitalityRatePlan $p) => (int) $p->room_type_id);

        return $rooms->map(function (HospitalityRoom $room) use ($org, $openFolioByRoomId, $defaultRateByType) {
            $folio = $openFolioByRoomId->get((int) $room->id);

            return $this->presentRoom($org, $room, $folio, $defaultRateByType);
        })->values()->all();
    }

    /**
     * @param  Collection<int, HospitalityRatePlan>|null  $defaultRateByType
     * @return array<string, mixed>
     */
    public function presentRoom(
        Organization $org,
        HospitalityRoom $room,
        ?HospitalityFolio $openFolio = null,
        ?Collection $defaultRateByType = null,
    ): array {
        $type = $room->roomType;
        $nightly = $this->nightlyRate($org, $room, $defaultRateByType);
        $status = (string) $room->status;
        $source = $this->occupancySource($room, $openFolio);
        $posSellable = $room->is_active
            && in_array($status, ['vacant', 'clean'], true)
            && $nightly > 0
            && $source === null;

        return [
            'id' => $room->id,
            'organization_id' => $room->organization_id,
            'branch_id' => $room->branch_id,
            'room_type_id' => $room->room_type_id,
            'room_number' => $room->room_number,
            'floor' => $room->floor,
            'status' => $status,
            'is_active' => (bool) $room->is_active,
            'guest_name' => $room->guest_name,
            'guest_phone' => $room->guest_phone,
            'checked_in_at' => optional($room->checked_in_at)?->toIso8601String(),
            'expected_checkout_at' => optional($room->expected_checkout_at)?->toIso8601String(),
            'sold_check_id' => $room->sold_check_id ? (int) $room->sold_check_id : null,
            'nightly_rate' => $nightly,
            'pos_sellable' => $posSellable,
            'occupancy_source' => $source,
            'open_folio_id' => $openFolio?->id ? (int) $openFolio->id : null,
            'open_folio_number' => $openFolio?->folio_number,
            'room_type' => $type ? [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'base_rate' => (float) $type->base_rate,
                'max_occupancy' => (int) $type->max_occupancy,
            ] : null,
        ];
    }

    public function occupancySource(HospitalityRoom $room, ?HospitalityFolio $openFolio = null): ?string
    {
        if ($room->sold_check_id) {
            return 'pos_room_sale';
        }
        if ($openFolio) {
            return 'pms_folio';
        }
        if ($room->status === 'occupied') {
            return 'pms_occupancy';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function createRange(Organization $org, array $input): array
    {
        $typeId = (int) ($input['room_type_id'] ?? 0);
        $type = HospitalityRoomType::query()
            ->where('organization_id', $org->id)
            ->where('id', $typeId)
            ->first();
        if (! $type) {
            throw ValidationException::withMessages([
                'room_type_id' => ['Select a room type.'],
            ]);
        }

        $numbers = self::expandRoomNumbers(
            (string) ($input['start_number'] ?? ''),
            (int) ($input['count'] ?? 0),
        );
        $taken = HospitalityRoom::query()
            ->where('organization_id', $org->id)
            ->whereIn('room_number', $numbers)
            ->pluck('room_number')
            ->all();
        if ($taken !== []) {
            throw ValidationException::withMessages([
                'start_number' => ['Already in inventory: '.implode(', ', $taken)],
            ]);
        }

        $floor = isset($input['floor']) ? trim((string) $input['floor']) : '';
        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;

        return DB::transaction(function () use ($org, $type, $numbers, $floor, $branchId) {
            $created = [];
            foreach ($numbers as $number) {
                $room = HospitalityRoom::create([
                    'organization_id' => $org->id,
                    'branch_id' => $branchId,
                    'room_type_id' => $type->id,
                    'room_number' => $number,
                    'floor' => $floor !== '' ? $floor : null,
                    'status' => 'vacant',
                    'is_active' => true,
                ]);
                $room->setRelation('roomType', $type);
                $created[] = $room;
            }

            return $this->presentMany($org, collect($created));
        });
    }

    public function assertInventoryStatus(?string $status, bool $creating): void
    {
        if ($status === null || $status === '') {
            return;
        }
        if ($status === 'occupied') {
            throw ValidationException::withMessages([
                'status' => ['Do not mark rooms occupied here. Assign the guest at Front desk, or sell the stay from Hotel POS.'],
            ]);
        }
        if (! in_array($status, self::HOUSEKEEPING_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid room status.'],
            ]);
        }
        if ($creating && ! in_array($status, ['vacant', 'clean'], true)) {
            throw ValidationException::withMessages([
                'status' => ['New rooms start vacant or clean so Hotel POS can sell them.'],
            ]);
        }
    }

    public function assertCanChangeStatus(Organization $org, HospitalityRoom $room, ?string $nextStatus): void
    {
        if ($nextStatus === null || $nextStatus === '' || $nextStatus === $room->status) {
            return;
        }
        $this->assertInventoryStatus($nextStatus, false);
        $openFolio = $this->openFolioForRoom($org, (int) $room->id);
        if ($room->status === 'occupied' || $room->sold_check_id || $openFolio) {
            throw ValidationException::withMessages([
                'status' => $room->sold_check_id
                    ? ['This room is a Hotel POS stay. Check out at Front desk or void the check — do not clear occupancy here.']
                    : ['This room has an in-house guest. Check out or reassign at Front desk.'],
            ]);
        }
    }

    public function assertCanDelete(Organization $org, HospitalityRoom $room): void
    {
        $openFolio = $this->openFolioForRoom($org, (int) $room->id);
        if ($room->status === 'occupied' || $room->sold_check_id || $openFolio) {
            throw ValidationException::withMessages([
                'room' => ['Cannot delete an occupied room. Check the guest out first.'],
            ]);
        }
    }

    public function openFolioForRoom(Organization $org, int $roomId): ?HospitalityFolio
    {
        return HospitalityFolio::query()
            ->where('organization_id', $org->id)
            ->where('room_id', $roomId)
            ->where('status', 'open')
            ->first();
    }

    /**
     * @param  Collection<int, HospitalityRatePlan>|null  $defaultRateByType
     */
    protected function nightlyRate(Organization $org, HospitalityRoom $room, ?Collection $defaultRateByType): float
    {
        $typeId = (int) $room->room_type_id;
        if ($defaultRateByType && $typeId && $defaultRateByType->has($typeId)) {
            return round((float) $defaultRateByType->get($typeId)->amount, 2);
        }

        return app(HospitalityPosRoomSaleService::class)->nightlyRateForRoom($org, $room);
    }
}
