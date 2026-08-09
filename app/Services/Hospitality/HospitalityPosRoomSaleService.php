<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\HospitalityCheckLine;
use App\Models\HospitalityFolio;
use App\Models\HospitalityRatePlan;
use App\Models\HospitalityRoom;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sell vacant rooms from Hotel POS: nights × nightly rate, occupy on full payment,
 * auto-release when expected_checkout_at passes.
 */
class HospitalityPosRoomSaleService
{
    public const LINE_TYPE = 'room_stay';

    /**
     * @return list<array<string, mixed>>
     */
    public function listSellableRooms(Organization $org, ?string $q = null): array
    {
        if (! HospitalityServices::enabled($org, 'rooms')) {
            return [];
        }

        $query = HospitalityRoom::query()
            ->with(['roomType:id,code,name,base_rate'])
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->whereIn('status', ['vacant', 'clean'])
            ->orderBy('room_number');

        if ($q = trim((string) $q)) {
            $query->where(function ($inner) use ($q) {
                $inner->where('room_number', 'like', "%{$q}%")
                    ->orWhere('floor', 'like', "%{$q}%")
                    ->orWhereHas('roomType', function ($t) use ($q) {
                        $t->where('name', 'like', "%{$q}%")
                            ->orWhere('code', 'like', "%{$q}%");
                    });
            });
        }

        return $query->get()->map(fn (HospitalityRoom $room) => $this->roomToSellableArray($org, $room))->all();
    }

    public function addRoomStayLine(
        HospitalityCheck $check,
        Organization $org,
        int $roomId,
        int $nights,
        Carbon $checkoutAt,
        ?string $guestName = null,
    ): HospitalityCheck {
        if (! HospitalityServices::enabled($org, 'rooms')) {
            throw ValidationException::withMessages([
                'service' => ['Rooms are not enabled for this organization.'],
            ]);
        }

        if ($nights < 1 || $nights > 90) {
            throw ValidationException::withMessages([
                'nights' => ['Nights must be between 1 and 90.'],
            ]);
        }

        if ($checkoutAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'checkout_at' => ['Checkout time must be in the future.'],
            ]);
        }

        return DB::transaction(function () use ($check, $org, $roomId, $nights, $checkoutAt, $guestName) {
            $room = HospitalityRoom::query()
                ->with('roomType')
                ->where('organization_id', $org->id)
                ->where('id', $roomId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $room->is_active || ! in_array($room->status, ['vacant', 'clean'], true)) {
                throw ValidationException::withMessages([
                    'room_id' => ["Room {$room->room_number} is not available ({$room->status})."],
                ]);
            }

            $alreadyOnCheck = HospitalityCheckLine::query()
                ->where('check_id', $check->id)
                ->whereNotNull('modifiers')
                ->get()
                ->contains(function (HospitalityCheckLine $line) use ($roomId) {
                    $mods = is_array($line->modifiers) ? $line->modifiers : [];

                    return ($mods['type'] ?? null) === self::LINE_TYPE
                        && (int) ($mods['room_id'] ?? 0) === $roomId;
                });
            if ($alreadyOnCheck) {
                throw ValidationException::withMessages([
                    'room_id' => ["Room {$room->room_number} is already on this check."],
                ]);
            }

            $nightly = $this->nightlyRateForRoom($org, $room);
            if ($nightly <= 0) {
                throw ValidationException::withMessages([
                    'room_id' => ["Room {$room->room_number} has no nightly rate. Set a room type base rate or default rate plan."],
                ]);
            }

            $qty = (float) $nights;
            $lineTotal = round($nightly * $qty, 2);
            $sort = (int) HospitalityCheckLine::query()->where('check_id', $check->id)->max('sort_order') + 1;
            $checkoutIso = $checkoutAt->toIso8601String();
            $desc = sprintf(
                'Room %s · %d night%s · out %s',
                $room->room_number,
                $nights,
                $nights === 1 ? '' : 's',
                $checkoutAt->timezone(config('app.timezone'))->format('d M Y H:i'),
            );

            HospitalityCheckLine::create([
                'organization_id' => $check->organization_id,
                'check_id' => $check->id,
                'product_id' => null,
                'product_code' => null,
                'description' => $desc,
                'qty' => $qty,
                'unit_price' => $nightly,
                'line_total' => $lineTotal,
                'vat_amount' => 0,
                'vat_id' => null,
                'modifiers' => [
                    'type' => self::LINE_TYPE,
                    'room_id' => (int) $room->id,
                    'room_number' => (string) $room->room_number,
                    'room_type' => $room->roomType?->name,
                    'nights' => $nights,
                    'nightly_rate' => $nightly,
                    'checkout_at' => $checkoutIso,
                ],
                'sort_order' => $sort,
            ]);

            $guest = trim((string) ($guestName ?? $check->guest_name ?? ''));
            if ($guest !== '') {
                $check->update(['guest_name' => mb_substr($guest, 0, 160)]);
            }

            $meta = is_array($check->meta) ? $check->meta : [];
            $meta['room_stays'] = array_values(array_merge(
                is_array($meta['room_stays'] ?? null) ? $meta['room_stays'] : [],
                [[
                    'room_id' => (int) $room->id,
                    'room_number' => (string) $room->room_number,
                    'nights' => $nights,
                    'checkout_at' => $checkoutIso,
                    'nightly_rate' => $nightly,
                ]],
            ));
            $check->update(['meta' => $meta]);

            return app(HospitalityCheckService::class)->recalculate($check->fresh());
        });
    }

    /**
     * After full payment, occupy rooms sold on the check until expected checkout.
     */
    public function occupyRoomsFromSettledCheck(HospitalityCheck $check, User $user): void
    {
        $lines = HospitalityCheckLine::query()
            ->where('check_id', $check->id)
            ->whereNotNull('modifiers')
            ->get()
            ->filter(function (HospitalityCheckLine $line) {
                $mods = is_array($line->modifiers) ? $line->modifiers : [];

                return ($mods['type'] ?? null) === self::LINE_TYPE;
            });

        if ($lines->isEmpty()) {
            return;
        }

        $guestName = trim((string) ($check->guest_name ?? '')) ?: 'Guest';

        foreach ($lines as $line) {
            $mods = is_array($line->modifiers) ? $line->modifiers : [];
            $roomId = (int) ($mods['room_id'] ?? 0);
            if ($roomId < 1) {
                continue;
            }
            $checkoutRaw = $mods['checkout_at'] ?? null;
            $checkoutAt = $checkoutRaw ? Carbon::parse($checkoutRaw) : now()->addDay()->setTime(10, 0);

            $room = HospitalityRoom::query()
                ->where('organization_id', $check->organization_id)
                ->where('id', $roomId)
                ->lockForUpdate()
                ->first();
            if (! $room) {
                continue;
            }
            if (in_array($room->status, ['occupied', 'ooo'], true) && (int) $room->sold_check_id !== (int) $check->id) {
                throw ValidationException::withMessages([
                    'room_id' => ["Room {$room->room_number} was taken before payment completed."],
                ]);
            }
            $openFolio = HospitalityFolio::query()
                ->where('organization_id', $check->organization_id)
                ->where('room_id', $roomId)
                ->where('status', 'open')
                ->exists();
            if ($openFolio) {
                throw ValidationException::withMessages([
                    'room_id' => ["Room {$room->room_number} has an open guest folio — use Front desk / folio stay, not POS room sale."],
                ]);
            }

            $room->update([
                'status' => 'occupied',
                'guest_name' => mb_substr($guestName, 0, 160),
                'guest_phone' => null,
                'checked_in_at' => now(),
                'expected_checkout_at' => $checkoutAt,
                'sold_check_id' => $check->id,
            ]);
        }
    }

    /**
     * Free rooms whose expected checkout time has passed.
     *
     * @return array{released: int}
     */
    public function releaseExpiredStays(?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? now();
        $released = 0;

        HospitalityRoom::query()
            ->where('status', 'occupied')
            ->whereNotNull('sold_check_id') // Hotel POS prepaid stays only — never auto-vacate PMS folio guests.
            ->whereNotNull('expected_checkout_at')
            ->where('expected_checkout_at', '<=', $asOf)
            ->orderBy('id')
            ->chunkById(100, function ($rooms) use (&$released) {
                foreach ($rooms as $room) {
                    /** @var HospitalityRoom $room */
                    $openFolio = HospitalityFolio::query()
                        ->where('organization_id', $room->organization_id)
                        ->where('room_id', $room->id)
                        ->where('status', 'open')
                        ->exists();
                    if ($openFolio) {
                        // PMS folio still owns the room — clear POS stamp only.
                        $room->update([
                            'sold_check_id' => null,
                            'expected_checkout_at' => null,
                        ]);
                        continue;
                    }
                    // Send to housekeeping (dirty), not vacant — same as front-desk check-out.
                    $room->update([
                        'status' => 'dirty',
                        'guest_name' => null,
                        'guest_phone' => null,
                        'checked_in_at' => null,
                        'expected_checkout_at' => null,
                        'sold_check_id' => null,
                    ]);
                    $released++;
                }
            });

        return ['released' => $released];
    }

    public function nightlyRateForRoom(Organization $org, HospitalityRoom $room): float
    {
        $roomTypeId = $room->room_type_id;
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

        return round((float) ($room->roomType?->base_rate ?? 0), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function roomToSellableArray(Organization $org, HospitalityRoom $room): array
    {
        $rate = $this->nightlyRateForRoom($org, $room);

        return [
            'id' => $room->id,
            'room_number' => $room->room_number,
            'floor' => $room->floor,
            'status' => $room->status,
            'nightly_rate' => $rate,
            'room_type' => $room->roomType ? [
                'id' => $room->roomType->id,
                'code' => $room->roomType->code,
                'name' => $room->roomType->name,
                'base_rate' => (float) $room->roomType->base_rate,
            ] : null,
            // Synthetic catalog-shaped fields so the POS tile UI can reuse product rendering patterns.
            'product_code' => 'ROOM-'.$room->id,
            'product_name' => 'Room '.$room->room_number
                .($room->roomType?->name ? ' · '.$room->roomType->name : ''),
            'unit_price' => $rate,
            'is_room' => true,
            'has_image' => false,
            'image_url' => null,
            'is_popular' => false,
            'menu_group' => 'rooms',
        ];
    }
}
